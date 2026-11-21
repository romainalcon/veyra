<x-mail::message>
# {{ $notification->event->headline($notification->locale) }} — {{ $notification->monitorName }}

{{ $notification->summary() }}

<x-mail::table>
| | |
|:--|:--|
| {{ __('Organization') }} | {{ $notification->organizationName }} |
| {{ __('Monitor') }} | {{ $notification->monitorName }} |
@if ($notification->cause)
| {{ __('Cause') }} | {{ $notification->cause }} |
@endif
@if ($notification->startedAt)
| {{ __('Started') }} | {{ $notification->startedAt }} UTC |
@endif
@if ($notification->resolvedAt)
| {{ __('Resolved') }} | {{ $notification->resolvedAt }} UTC |
@endif
@if ($notification->certificateExpiresAt)
| {{ __('Certificate expires') }} | {{ $notification->certificateExpiresAt }} UTC |
@endif
@if ($notification->certificateIssuer)
| {{ __('Issuer') }} | {{ $notification->certificateIssuer }} |
@endif
</x-mail::table>

<x-mail::button :url="$notification->url">
{{ __('Open in Veyra') }}
</x-mail::button>

{{ __('You receive this e-mail because this address is configured on a Veyra notification channel.') }}
</x-mail::message>
