<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One audited action (ADR-046). Entries are append-only: the model refuses
 * updates and deletes; only the retention purge (query builder, in batches)
 * and the organization cascade remove rows. Actor and subject names are
 * copies, so an entry stays readable after they are deleted. `changes`
 * holds allow-listed fields only (never secrets).
 *
 * @property string $id
 * @property string $organization_id
 * @property AuditActorType $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property array{before?: array<string, mixed>, after?: array<string, mixed>}|null $changes
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $created_at
 * @property-read Organization $organization
 */
#[Fillable(['organization_id', 'actor_type', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'subject_label', 'changes', 'metadata', 'created_at'])]
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'action' => AuditAction::class,
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit log entries are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Audit log entries are append-only.'));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
