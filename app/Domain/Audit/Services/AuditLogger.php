<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Contracts\Impersonation;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * Writes audit entries (ADR-046). Actions call it inside the transaction
 * that performs the change, so an entry exists exactly when the change was
 * committed. The actor is whoever is authenticated on the current guard: a
 * member on the interface, an API token on the API, nobody (system) in
 * scheduled jobs. `before` and `after` must come from AuditSnapshot, which
 * only keeps allow-listed, secret-free fields; for updates only the changed
 * fields are stored, and an update that changed nothing is not recorded.
 */
class AuditLogger
{
    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * @param  array<string, mixed>|null  $before  state before the action (null on creation)
     * @param  array<string, mixed>|null  $after  state after the action (null on deletion)
     * @param  array<string, mixed>  $metadata  extra, secret-free context
     */
    public function record(
        Organization|string $organization,
        AuditAction $action,
        ?AuditSubject $subject = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): ?AuditLog {
        $before = $before === null ? null : self::normalize($before);
        $after = $after === null ? null : self::normalize($after);

        if ($before !== null && $after !== null) {
            $changed = array_keys(array_filter(
                $after + array_fill_keys(array_keys($before), null),
                fn (mixed $value, string $key): bool => ($before[$key] ?? null) !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($changed === [] && $metadata === []) {
                return null;
            }

            $before = array_intersect_key($before, array_flip($changed));
            $after = array_intersect_key($after, array_flip($changed));
        }

        $changes = array_filter(['before' => $before, 'after' => $after], fn (?array $values): bool => $values !== null && $values !== []);

        return AuditLog::query()->create([
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
            ...$this->actor(),
            'action' => $action,
            'subject_type' => $subject?->type,
            'subject_id' => $subject?->id,
            'subject_label' => $subject === null ? null : mb_substr($subject->label, 0, 255),
            'changes' => $changes === [] ? null : $changes,
            'metadata' => ($metadata + $this->impersonationMetadata()) ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{actor_type: AuditActorType, actor_id: string|null, actor_name: string|null}
     */
    private function actor(): array
    {
        // A member on the interface, the API token on the API (ADR-044); nobody in jobs.
        $actor = request()->user() ?? request()->user('api');

        return match (true) {
            $actor instanceof User => ['actor_type' => AuditActorType::User, 'actor_id' => $actor->id, 'actor_name' => $actor->name],
            $actor instanceof ApiToken => ['actor_type' => AuditActorType::ApiToken, 'actor_id' => $actor->id, 'actor_name' => $actor->name],
            default => ['actor_type' => AuditActorType::System, 'actor_id' => null, 'actor_name' => null],
        };
    }

    /**
     * An operator acting as a member (ADR-038) is named next to the member.
     *
     * @return array<string, string>
     */
    private function impersonationMetadata(): array
    {
        // Impersonation lives in the session; jobs and the API have none.
        if (! request()->hasSession()) {
            return [];
        }

        $current = $this->impersonation->current(request());

        return $current === null ? [] : ['impersonated_by' => $current['operator']];
    }

    /**
     * JSON-safe scalars: enums become their value, dates ISO 8601 strings.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function normalize(array $values): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof CarbonInterface => $value->toIso8601String(),
            is_array($value) => self::normalize($value),
            default => $value,
        }, $values);
    }
}
