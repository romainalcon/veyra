<?php

namespace App\Domain\StatusPages\Jobs;

use App\Domain\Notifications\Enums\DeliveryStatus;
use App\Domain\StatusPages\Models\StatusPageAnnouncement;
use App\Domain\StatusPages\Models\StatusPageSubscriber;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fans an announcement out to the subscribers confirmed when it was made,
 * one bounded batch per run (the job re-dispatches itself with a cursor).
 * The unique (announcement, subscriber) key makes retries and concurrent
 * runs create each delivery once.
 */
class SendStatusPageAnnouncement implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const BATCH_SIZE = 500;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $announcementId, public readonly ?string $afterSubscriberId = null)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return $this->announcementId.':'.($this->afterSubscriberId ?? '');
    }

    public function handle(): void
    {
        $announcement = StatusPageAnnouncement::query()->with('statusPage')->find($this->announcementId);

        if ($announcement === null || ! $announcement->statusPage->is_published || ! $announcement->statusPage->subscriptions_enabled) {
            return;
        }

        $subscriberIds = StatusPageSubscriber::query()
            ->where('status_page_id', $announcement->status_page_id)
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '<=', $announcement->created_at)
            ->when($this->afterSubscriberId, fn ($query, string $after) => $query->where('id', '>', $after))
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        foreach ($subscriberIds as $subscriberId) {
            $id = (string) Str::uuid7();

            $inserted = DB::table('status_page_subscriber_deliveries')->insertOrIgnore([
                'id' => $id,
                'announcement_id' => $announcement->id,
                'subscriber_id' => $subscriberId,
                'status' => DeliveryStatus::Pending->value,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                SendSubscriberAnnouncement::dispatch($id);
            }
        }

        if ($subscriberIds->count() === self::BATCH_SIZE) {
            self::dispatch($announcement->id, (string) $subscriberIds->last());
        }
    }
}
