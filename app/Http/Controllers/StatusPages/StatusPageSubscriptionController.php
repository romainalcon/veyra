<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\StatusPages\Actions\ConfirmSubscription;
use App\Domain\StatusPages\Actions\SubscribeToStatusPage;
use App\Domain\StatusPages\Actions\Unsubscribe;
use App\Domain\StatusPages\Enums\SubscriptionResult;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageSubscriber;
use App\Domain\StatusPages\Support\SubscriptionLinks;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Visitor subscriptions (ADR-036), sessionless like the status page itself:
 * no cookies and no CSRF token. Subscribing answers the same way whether the
 * address is new, pending or confirmed. Confirmation and unsubscribe links
 * are signed; their GET only shows a button, so link scanners change nothing,
 * and the unsubscribe POST also serves RFC 8058 one-click requests.
 */
class StatusPageSubscriptionController extends Controller
{
    public function subscribe(Request $request, string $slug, SubscribeToStatusPage $subscribe): JsonResponse
    {
        return $this->subscribeTo($request, PublicStatusPageController::published($slug), $subscribe);
    }

    public function subscribeTo(Request $request, StatusPage $statusPage, SubscribeToStatusPage $subscribe): JsonResponse
    {
        abort_unless($statusPage->is_published, 404);

        PublicStatusPageController::useOrganizationLocale($statusPage);

        $validator = Validator::make($request->only(['email', 'website']), [
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        if ($validator->fails()) {
            if ($validator->errors()->has('website')) {
                return $this->accepted();
            }

            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        $pageKey = 'status-page-subscribe-page:'.$statusPage->id;

        if (RateLimiter::tooManyAttempts($pageKey, (int) config('veyra.status_pages.subscriptions_per_page_per_hour', 100))) {
            return response()->json(['message' => __('Too many subscription requests. Please try again later.')], 429);
        }

        RateLimiter::hit($pageKey, 3600);

        return match ($subscribe->handle($statusPage, (string) $validator->validated()['email'])) {
            SubscriptionResult::Accepted => $this->accepted(),
            SubscriptionResult::Unavailable => response()->json([
                'message' => __('Subscriptions are not available for this page right now.'),
                'errors' => ['email' => [__('Subscriptions are not available for this page right now.')]],
            ], 422),
        };
    }

    public function showConfirm(Request $request, string $subscriber): Response
    {
        return $this->confirmation($request, $subscriber, false);
    }

    public function confirm(Request $request, string $subscriber, ConfirmSubscription $confirmSubscription): Response
    {
        return $this->confirmation($request, $subscriber, true, $confirmSubscription);
    }

    public function showUnsubscribe(Request $request, string $subscriber): Response
    {
        return $this->unsubscription($request, $subscriber, false);
    }

    public function unsubscribe(Request $request, string $subscriber, Unsubscribe $unsubscribe): Response
    {
        return $this->unsubscription($request, $subscriber, true, $unsubscribe);
    }

    private function confirmation(Request $request, string $subscriberId, bool $apply, ?ConfirmSubscription $confirmSubscription = null): Response
    {
        $subscriber = $request->hasValidSignature() ? $this->subscriber($subscriberId) : null;

        if ($subscriber === null || ! $subscriber->statusPage->is_published || ! $subscriber->statusPage->subscriptions_enabled) {
            return $this->page($request, 'invalid');
        }

        $statusPage = $subscriber->statusPage;

        if ($apply && $confirmSubscription !== null) {
            $confirmSubscription->handle($subscriber);
        }

        return $this->page($request, $apply || $subscriber->confirmed_at !== null ? 'confirmed' : 'confirm', $statusPage);
    }

    private function unsubscription(Request $request, string $subscriberId, bool $apply, ?Unsubscribe $unsubscribe = null): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->page($request, 'invalid');
        }

        $subscriber = $this->subscriber($subscriberId);

        if ($apply && $unsubscribe !== null) {
            $unsubscribe->handle($subscriberId);
        }

        return $this->page($request, $apply || $subscriber === null ? 'unsubscribed' : 'unsubscribe', $subscriber?->statusPage);
    }

    private function subscriber(string $id): ?StatusPageSubscriber
    {
        return preg_match('/^[0-9a-f-]{36}$/', $id) === 1
            ? StatusPageSubscriber::query()->with('statusPage')->find($id)
            : null;
    }

    private function page(Request $request, string $state, ?StatusPage $statusPage = null): Response
    {
        if ($statusPage !== null) {
            PublicStatusPageController::useOrganizationLocale($statusPage);
        }

        $response = Inertia::render('status/Subscription', [
            'state' => $state,
            'page' => $statusPage === null || ! $statusPage->is_published ? null : [
                'title' => $statusPage->title,
                'url' => SubscriptionLinks::page($statusPage),
            ],
            'actionUrl' => in_array($state, ['confirm', 'unsubscribe'], true) ? $request->fullUrl() : null,
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['message' => __('Check your inbox: we sent you a link to confirm your subscription.')], 202);
    }
}
