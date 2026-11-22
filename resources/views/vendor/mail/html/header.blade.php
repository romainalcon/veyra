@props(['url'])
{{-- Veyra header (ADR-035): text only, no remote image, so nothing is fetched when the e-mail is opened. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="brand-mark">&nbsp;</span>&nbsp;{{ $slot }}
</a>
</td>
</tr>
