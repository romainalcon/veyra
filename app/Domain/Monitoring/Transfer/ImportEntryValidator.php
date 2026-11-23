<?php

namespace App\Domain\Monitoring\Transfer;

use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Organizations\Models\Organization;
use App\Http\Requests\Monitors\SaveMonitorRequest;
use App\Models\User;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

/**
 * Validates one imported monitor exactly like the interface (ADR-047): the
 * entry is mapped onto the monitor form's fields and run through
 * SaveMonitorRequest, so authorization, SSRF checks (every DNS answer,
 * ports, credentials in URLs), DNS record rules, interval minimums and
 * limits are the same code path as creating the monitor by hand.
 */
final class ImportEntryValidator
{
    /**
     * @param  array<array-key, mixed>  $entry  one element of the document's `monitors`
     * @return MonitorData|list<string> the validated data, or the error messages
     */
    public static function validate(Organization $organization, User $user, array $entry): MonitorData|array
    {
        $request = SaveMonitorRequest::create('/', 'POST', self::formInput($entry));
        $request->setContainer(app())->setRedirector(app(Redirector::class));
        $request->setUserResolver(fn (): User => $user);

        $route = (new Route('POST', '/', []))->bind($request);
        $route->setParameter('organization', $organization);
        $request->setRouteResolver(fn (): Route => $route);

        try {
            $request->validateResolved();
        } catch (ValidationException $exception) {
            return array_values(array_unique(array_merge(...array_values($exception->errors()))));
        }

        return $request->toData();
    }

    /**
     * Export fields onto monitor form fields. Values are passed through
     * unchanged (the form rules reject wrong types); secrets are never read
     * from a document, even if one was added by hand.
     *
     * @param  array<array-key, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function formInput(array $entry): array
    {
        $type = MonitorType::tryFrom(is_string($entry['type'] ?? null) ? $entry['type'] : '');
        $section = fn (string $key): array => is_array($entry[$key] ?? null) ? $entry[$key] : [];

        $input = [
            'type' => $entry['type'] ?? null,
            'name' => $entry['name'] ?? null,
            'interval' => $entry['interval_seconds'] ?? null,
            'timeout' => $entry['timeout_seconds'] ?? null,
            'tags_submitted' => true,
            'tags' => $entry['tags'] ?? [],
            'notification_channels_submitted' => false,
        ];

        return $input + match ($type) {
            MonitorType::Http => array_intersect_key($section('http'), array_flip([
                'url', 'method', 'expected_statuses', 'follow_redirects', 'keyword', 'keyword_mode', 'keyword_case_sensitive', 'latency_threshold_ms',
            ])),
            MonitorType::Tcp => array_intersect_key($section('tcp'), array_flip(['host', 'port'])),
            MonitorType::Dns => [
                ...array_intersect_key($section('dns'), array_flip(['record_name', 'record_type', 'resolver', 'match_mode'])),
                'expected_values' => self::lines($section('dns')['expected_values'] ?? null),
            ],
            MonitorType::Heartbeat => ['grace_minutes' => $section('heartbeat')['grace_minutes'] ?? null],
            null => [],
        };
    }

    private static function lines(mixed $values): mixed
    {
        if (is_array($values) && array_filter($values, fn (mixed $value): bool => ! is_string($value)) === []) {
            return implode("\n", $values);
        }

        return $values;
    }
}
