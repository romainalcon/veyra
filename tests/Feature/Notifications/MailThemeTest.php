<?php

use App\Domain\Notifications\Channels\NotificationMessage;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Mail\IncidentNotificationMail;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Notifications\OrganizationInvitationNotification;
use App\Models\User;
use Illuminate\Mail\Markdown;

/*
 * Veyra mail theme (ADR-035): branded header and colours, and nothing loaded
 * from a remote host when the e-mail is opened.
 */
function expectVeyraTheme(string $html): void
{
    expect($html)
        ->toContain('brand-mark')
        ->toContain('#4f46e5')
        ->not->toContain('<img')
        ->not->toContain('laravel.com');
}

it('renders incident e-mails with the Veyra theme', function () {
    $mail = new IncidentNotificationMail(new NotificationMessage(
        event: NotificationEvent::Opened,
        organizationName: 'Acme',
        monitorName: 'Shop',
        cause: 'HTTP 503',
        startedAt: '2026-11-22 10:00',
        resolvedAt: null,
        durationSeconds: null,
        acknowledgedBy: null,
        url: 'https://veyra.test/acme/incidents/1',
    ));

    expectVeyraTheme($mail->render());
});

it('renders invitation e-mails with the Veyra theme', function () {
    $message = (new OrganizationInvitationNotification('Acme', 'Owner', OrganizationRole::Member, 'token'))
        ->toMail(User::factory()->make());

    expectVeyraTheme((string) app(Markdown::class)->render($message->markdown, $message->data()));
});

it('keeps the plain-text version free of markup', function () {
    $mail = new IncidentNotificationMail(new NotificationMessage(
        event: NotificationEvent::Resolved,
        organizationName: 'Acme',
        monitorName: 'Shop',
        cause: null,
        startedAt: '2026-11-22 10:00',
        resolvedAt: '2026-11-22 10:05',
        durationSeconds: 300,
        acknowledgedBy: null,
        url: 'https://veyra.test/acme/incidents/1',
    ));

    $text = (string) app(Markdown::class)->renderText('mail.incident-notification', ['notification' => $mail->notification]);

    expect($text)->toContain('Shop')->not->toContain('brand-mark');
});
