<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Audit\Services\AuditSnapshot;
use App\Domain\Notifications\Models\NotificationChannel;
use Illuminate\Support\Facades\DB;

class DeleteNotificationChannel
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(NotificationChannel $channel): void
    {
        DB::transaction(function () use ($channel): void {
            $this->audit->record($channel->organization_id, AuditAction::NotificationChannelDeleted, AuditSnapshot::channelSubject($channel), before: AuditSnapshot::channel($channel));

            $channel->delete();
        });
    }
}
