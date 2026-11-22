<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Entitlements\LimitReached;
use App\Domain\Entitlements\Services\Quota;
use App\Domain\StatusPages\Enums\SubscriptionResult;
use App\Domain\StatusPages\Jobs\SendSubscriptionConfirmation;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageSubscriber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a double opt-in subscription. New, pending and confirmed addresses
 * get the same answer; a confirmation e-mail goes out for new addresses and,
 * at most once per resend interval, for pending ones.
 */
class SubscribeToStatusPage
{
    public function __construct(private readonly Quota $quota) {}

    public function handle(StatusPage $statusPage, string $email): SubscriptionResult
    {
        if (! $statusPage->is_published || ! $statusPage->subscriptions_enabled) {
            return SubscriptionResult::Unavailable;
        }

        $email = StatusPageSubscriber::normalizeEmail($email);
        $hash = StatusPageSubscriber::hashEmail($email);

        try {
            $subscriberId = DB::transaction(function () use ($statusPage, $email, $hash): ?string {
                $existing = StatusPageSubscriber::query()
                    ->where('status_page_id', $statusPage->id)
                    ->where('email_hash', $hash)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $resendAfter = now()->subMinutes((int) config('veyra.status_pages.confirmation_resend_minutes', 10));

                    if ($existing->confirmed_at !== null || ($existing->confirmation_sent_at !== null && $existing->confirmation_sent_at->isAfter($resendAfter))) {
                        return null;
                    }

                    $existing->update(['confirmation_sent_at' => now()]);

                    return $existing->id;
                }

                $this->quota->ensureCanAdd($statusPage->organization, Feature::StatusPageSubscribers);

                return StatusPageSubscriber::query()->create([
                    'organization_id' => $statusPage->organization_id,
                    'status_page_id' => $statusPage->id,
                    'email' => $email,
                    'email_hash' => $hash,
                    'confirmation_sent_at' => now(),
                ])->id;
            });
        } catch (LimitReached) {
            return SubscriptionResult::Unavailable;
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the same subscription.
            return SubscriptionResult::Accepted;
        }

        if ($subscriberId !== null) {
            SendSubscriptionConfirmation::dispatch($subscriberId)->afterCommit();
        }

        return SubscriptionResult::Accepted;
    }
}
