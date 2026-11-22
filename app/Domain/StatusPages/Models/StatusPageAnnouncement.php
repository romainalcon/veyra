<?php

namespace App\Domain\StatusPages\Models;

use App\Domain\StatusPages\Enums\AnnouncementEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One public announcement of a status page (an incident change or a planned
 * maintenance). The payload is frozen from whitelisted public fields only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $status_page_id
 * @property string $subject_id
 * @property AnnouncementEvent $event
 * @property array{component?: string, components?: list<string>, message?: string|null, title?: string, description?: string|null, starts_at?: string, ends_at?: string, at?: string} $payload
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StatusPage $statusPage
 */
#[Fillable(['organization_id', 'status_page_id', 'subject_id', 'event', 'payload'])]
class StatusPageAnnouncement extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AnnouncementEvent::class,
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<StatusPage, $this>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }
}
