<x-mail::message>
# {{ $notification->event->headline() }} — {{ $notification->monitorName }}

{{ $notification->summary() }}

<x-mail::table>
| | |
|:--|:--|
| Organization | {{ $notification->organizationName }} |
| Monitor | {{ $notification->monitorName }} |
@if ($notification->cause)
| Cause | {{ $notification->cause }} |
@endif
@if ($notification->startedAt)
| Started | {{ $notification->startedAt }} UTC |
@endif
@if ($notification->resolvedAt)
| Resolved | {{ $notification->resolvedAt }} UTC |
@endif
@if ($notification->certificateExpiresAt)
| Certificate expires | {{ $notification->certificateExpiresAt }} UTC |
@endif
@if ($notification->certificateIssuer)
| Issuer | {{ $notification->certificateIssuer }} |
@endif
</x-mail::table>

<x-mail::button :url="$notification->url">
Open in Veyra
</x-mail::button>

You receive this e-mail because this address is configured on a Veyra notification channel.
</x-mail::message>
