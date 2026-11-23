@php($mail = \App\Domain\Reports\Mail\WeeklyReportMail::class)
<x-mail::message>
# {{ __('Weekly report') }} — {{ $report->organizationName }}

{{ __(':period (:timezone)', ['period' => $report->periodLabel, 'timezone' => $report->timezone]) }}

<x-mail::table>
| | |
|:--|:--|
| {{ __('Uptime') }} | {{ $mail::percent($report->overall['uptime']) }}@if ($report->overall['partial']) ({{ __('partial data') }})@endif |
| {{ __('Monitors') }} | {{ $report->monitorsCount }} |
| {{ __('Incidents') }} | {{ $report->incidentsCount }} |
| {{ __('Downtime') }} | {{ $downtime }} |
</x-mail::table>

## {{ __('Lowest uptime') }}

@if ($report->lowestUptime === [])
{{ __('Every monitor with data stayed up all week.') }}
@else
<x-mail::table>
| {{ __('Monitor') }} | {{ __('Uptime') }} |
|:--|--:|
@foreach ($report->lowestUptime as $row)
| {{ $mail::cell($row['name']) }} | {{ $mail::percent($row['uptime']) }}@if ($row['partial']) *@endif |
@endforeach
</x-mail::table>
@endif

@if ($report->slowest !== [])
## {{ __('Slowest monitors') }}

<x-mail::table>
| {{ __('Monitor') }} | {{ __('Average response') }} |
|:--|--:|
@foreach ($report->slowest as $row)
| {{ $mail::cell($row['name']) }} | {{ $row['latency_ms'] }} ms |
@endforeach
</x-mail::table>
@endif

@if ($report->incidents !== [])
## {{ __('Incidents') }}

<x-mail::table>
| {{ __('Monitor') }} | {{ __('Started') }} | {{ __('Duration') }} |
|:--|:--|--:|
@foreach ($report->incidents as $incident)
| {{ $mail::cell($incident['monitor']) }} | {{ $incident['started_at'] }} | {{ $incident['duration'] }}@if ($incident['open']) ({{ __('ongoing') }})@endif |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="$dashboardUrl">
{{ __('Open in Veyra') }}
</x-mail::button>

@if (collect($report->lowestUptime)->contains('partial', true))
{{ __('* partial data: fewer checks than expected were recorded.') }}
@endif

{{ __('You receive this report because you enabled it in the organization settings.') }} [{{ __('Manage the weekly report') }}]({{ $settingsUrl }})
</x-mail::message>
