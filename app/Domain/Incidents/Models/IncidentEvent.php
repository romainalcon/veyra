<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Incidents\Enums\IncidentEventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $incident_id
 * @property IncidentEventType $type
 * @property string|null $user_id
 * @property string|null $message
 * @property array<string, scalar|null>|null $metadata
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
#[Fillable(['incident_id', 'type', 'user_id', 'message', 'metadata', 'created_at'])]
class IncidentEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IncidentEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
