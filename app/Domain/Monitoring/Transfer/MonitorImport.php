<?php

namespace App\Domain\Monitoring\Transfer;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\Actions\CreateMonitor;
use App\Domain\Monitoring\Actions\SetMonitorEnabled;
use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Support\HeartbeatToken;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Imports an export document (ADR-047). `preview()` is the dry run: it
 * validates every entry with the interface rules, flags monitors that
 * already exist (same type and target) and checks the active monitor
 * quota, without writing anything. `import()` runs the same preview again
 * (nothing from the dry run is trusted) and creates every new monitor in one
 * transaction: if one entry is invalid or the quota is reached, nothing is
 * created.
 */
class MonitorImport
{
    public function __construct(
        private readonly CreateMonitor $createMonitor,
        private readonly SetMonitorEnabled $setMonitorEnabled,
        private readonly Quota $quota,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Decodes and checks the envelope of an uploaded document.
     *
     * @return array{format: string, version: int, monitors: list<array<array-key, mixed>>}
     *
     * @throws ValidationException
     */
    public static function decode(string $contents): array
    {
        try {
            $document = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['file' => __('The file is not valid JSON.')]);
        }

        $validator = Validator::make(is_array($document) ? $document : [], [
            'format' => ['required', 'string', 'in:'.MonitorExport::FORMAT],
            'version' => ['required', 'integer', 'in:'.MonitorExport::VERSION],
            'monitors' => ['required', 'array', 'list', 'min:1', 'max:'.self::maxEntries()],
            'monitors.*' => ['array'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            throw ValidationException::withMessages(['file' => match (true) {
                $errors->has('format'), $errors->has('version') => __('This is not a Veyra monitor export (format :format, version :version).', ['format' => MonitorExport::FORMAT, 'version' => MonitorExport::VERSION]),
                default => __('The file must contain between 1 and :count monitors.', ['count' => self::maxEntries()]),
            }]);
        }

        /** @var array{format: string, version: int, monitors: list<array<array-key, mixed>>} $document */
        return $document;
    }

    public static function maxEntries(): int
    {
        return max(1, (int) config('veyra.monitors.max_per_import', 100));
    }

    /**
     * @param  array{monitors: list<array<array-key, mixed>>}  $document
     */
    public function preview(Organization $organization, User $user, array $document, bool $skipExisting = true): ImportPreview
    {
        $existing = array_fill_keys($organization->monitors()
            ->with(['httpConfig', 'tcpConfig', 'dnsConfig'])
            ->get()
            ->map(fn (Monitor $monitor): string => self::fingerprintOfMonitor($monitor))
            ->all(), true);

        $rows = [];
        $seen = [];

        foreach ($document['monitors'] as $index => $entry) {
            $result = ImportEntryValidator::validate($organization, $user, $entry);
            $name = is_string($entry['name'] ?? null) ? mb_substr($entry['name'], 0, 100) : '';
            $excluded = is_array($entry['excluded'] ?? null) ? array_values(array_filter($entry['excluded'], 'is_string')) : [];

            if (is_array($result)) {
                $rows[] = new ImportRow($index, $name, is_string($entry['type'] ?? null) ? $entry['type'] : '', null, ImportRowStatus::Invalid, $result, $excluded);

                continue;
            }

            $fingerprint = self::fingerprintOfData($result);
            $duplicate = $skipExisting && (isset($existing[$fingerprint]) || isset($seen[$fingerprint]));
            $seen[$fingerprint] = true;

            $rows[] = new ImportRow(
                $index,
                $result->name,
                $result->type->value,
                self::target($result),
                $duplicate ? ImportRowStatus::Duplicate : ImportRowStatus::Valid,
                [],
                $excluded,
                $result,
                ($entry['enabled'] ?? true) !== false,
                $result->tags ?? [],
            );
        }

        $limit = $this->quota->limit($organization, Feature::Monitors);

        return new ImportPreview($rows, $limit === null ? null : ['used' => $this->quota->used($organization, Feature::Monitors), 'limit' => $limit]);
    }

    /**
     * @param  array{monitors: list<array<array-key, mixed>>}  $document
     * @return int the number of monitors created
     *
     * @throws ValidationException when an entry is invalid
     */
    public function import(Organization $organization, User $user, array $document, bool $skipExisting = true): int
    {
        $preview = $this->preview($organization, $user, $document, $skipExisting);

        if ($preview->invalidCount() > 0) {
            throw ValidationException::withMessages(['file' => trans_choice('{1} One monitor is invalid: fix the file and try again.|[2,*] :count monitors are invalid: fix the file and try again.', $preview->invalidCount())]);
        }

        $toCreate = $preview->creationOrder();

        return DB::transaction(function () use ($organization, $toCreate, $preview): int {
            foreach ($toCreate as $row) {
                /** @var MonitorData $data */
                $data = $row->data;
                $pingToken = $data->type === MonitorType::Heartbeat ? HeartbeatToken::generate() : null;

                $monitor = $this->createMonitor->handle($organization, $data, $pingToken, ['source' => 'import']);

                if (! $row->enabled) {
                    $this->setMonitorEnabled->handle($monitor, false);
                }
            }

            $this->audit->record($organization, AuditAction::MonitorsImported, metadata: [
                'created' => count($toCreate),
                'skipped' => $preview->duplicateCount(),
            ]);

            return count($toCreate);
        });
    }

    /**
     * Same type and destination; heartbeats have none, so their name is used.
     */
    public static function fingerprintOfData(MonitorData $data): string
    {
        return match ($data->type) {
            MonitorType::Http => self::fingerprint('http', $data->http?->method->value, $data->http?->url),
            MonitorType::Tcp => self::fingerprint('tcp', $data->tcp?->host, (string) $data->tcp?->port),
            MonitorType::Dns => self::fingerprint('dns', $data->dns?->recordName, $data->dns?->recordType->value, $data->dns?->resolver->value),
            MonitorType::Heartbeat => self::fingerprint('heartbeat', $data->name),
        };
    }

    public static function fingerprintOfMonitor(Monitor $monitor): string
    {
        return match ($monitor->type) {
            MonitorType::Http => self::fingerprint('http', $monitor->httpConfig?->method->value, $monitor->httpConfig?->url),
            MonitorType::Tcp => self::fingerprint('tcp', $monitor->tcpConfig?->host, (string) $monitor->tcpConfig?->port),
            MonitorType::Dns => self::fingerprint('dns', $monitor->dnsConfig?->record_name, $monitor->dnsConfig?->record_type->value, $monitor->dnsConfig?->resolver->value),
            MonitorType::Heartbeat => self::fingerprint('heartbeat', $monitor->name),
        };
    }

    private static function fingerprint(string ...$parts): string
    {
        return mb_strtolower(implode('|', array_map(fn (?string $part): string => rtrim(trim((string) $part), '.'), $parts)));
    }

    private static function target(MonitorData $data): ?string
    {
        return match ($data->type) {
            MonitorType::Http => $data->http === null ? null : "{$data->http->method->value} {$data->http->url}",
            MonitorType::Tcp => $data->tcp === null ? null : "{$data->tcp->host}:{$data->tcp->port}",
            MonitorType::Dns => $data->dns === null ? null : "{$data->dns->recordType->value} {$data->dns->recordName}",
            MonitorType::Heartbeat => null,
        };
    }
}
