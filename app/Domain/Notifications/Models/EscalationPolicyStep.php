<?php

namespace App\Domain\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One step of an escalation policy: its channels are notified once the
 * incident has been down for `delay_minutes` without acknowledgement.
 * Positions start at 1 and delays strictly increase with them.
 *
 * @property string $id
 * @property string $policy_id
 * @property int $position
 * @property int $delay_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EscalationPolicy $policy
 * @property-read Collection<int, NotificationChannel> $channels
 */
#[Fillable(['policy_id', 'position', 'delay_minutes'])]
class EscalationPolicyStep extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EscalationPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(EscalationPolicy::class, 'policy_id');
    }

    /**
     * @return BelongsToMany<NotificationChannel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'escalation_step_channels', 'step_id', 'channel_id');
    }
}
