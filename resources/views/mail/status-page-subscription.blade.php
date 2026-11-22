<x-mail::message>
# {{ __('Confirm your subscription') }}

{{ __('Someone, hopefully you, asked to receive updates from :page by e-mail.', ['page' => $pageTitle]) }}

<x-mail::button :url="$confirmUrl">
{{ __('Confirm my subscription') }}
</x-mail::button>

{{ __('This link is valid for :hours hours. If you did not ask for it, ignore this e-mail: nothing will be sent to you.', ['hours' => $validHours]) }}

[{{ $pageTitle }}]({{ $pageUrl }})
</x-mail::message>
