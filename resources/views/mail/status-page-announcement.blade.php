<x-mail::message>
# {{ $headline }}

@if ($event === \App\Domain\StatusPages\Enums\AnnouncementEvent::IncidentOpened)
{{ __('We are investigating an outage affecting this component.') }}
@elseif ($event === \App\Domain\StatusPages\Enums\AnnouncementEvent::IncidentResolved)
{{ __('This incident is resolved.') }}
@endif

@if ($message)
{{ $message }}
@endif

@if ($description)
{{ $description }}
@endif

<x-mail::table>
| | |
|:--|:--|
@if ($at)
| {{ __('Date') }} | {{ $at }} |
@endif
@if ($startsAt)
| {{ __('Starts') }} | {{ $startsAt }} |
@endif
@if ($endsAt)
| {{ __('Ends') }} | {{ $endsAt }} |
@endif
@if ($components !== '')
| {{ __('Components') }} | {{ $components }} |
@endif
</x-mail::table>

<x-mail::button :url="$pageUrl">
{{ __('View the status page') }}
</x-mail::button>

{{ __('You receive this e-mail because you subscribed to :page.', ['page' => $pageTitle]) }} [{{ __('Unsubscribe') }}]({{ $unsubscribeUrl }})
</x-mail::message>
