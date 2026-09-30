{{--
    The weekly summary (decision D-18), rendered by WeeklySummaryNotification
    in the recipient's locale.

    Every value arrives prepared and plain: figures, translated labels and
    lines, task titles and names. Each block is raw HTML written flush left
    with no blank line inside, so CommonMark keeps it as one HTML block and
    never re-reads a title that holds markdown characters; every value is
    escaped here. The blocks carry the direction and alignment of the locale
    (the published mail layout sets dir on the document) and the paragraph
    typography of Laravel's mail theme inline, because the theme inlines its
    rules only on real paragraphs. Tables use the theme's `table` class; a
    listed task links to its page only when the notification says the reader
    may open it. Log contents never appear: only counts.
--}}
@php
    $crmDirection = __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr';
    $crmAlign = $crmDirection === 'rtl' ? 'right' : 'left';
    $crmOpposite = $crmDirection === 'rtl' ? 'left' : 'right';
    $crmBlock = "font-size: 16px; line-height: 1.5em; margin: 0 0 1em 0; text-align: {$crmAlign};";
    $crmHeading = "color: #18181b; font-size: 18px; font-weight: bold; margin: 1.5em 0 0.5em 0; text-align: {$crmAlign};";
    $crmCell = "padding: 8px 6px; text-align: {$crmAlign};";
    $crmNumber = "padding: 8px 6px; text-align: {$crmOpposite}; white-space: nowrap;";
    $crmMuted = "color: #71717a; font-size: 14px; margin: 0 0 1em 0; text-align: {$crmAlign};";
@endphp
<x-mail::message>
<div dir="{{ $crmDirection }}" style="color: #18181b; font-size: 18px; font-weight: bold; margin: 0 0 1em 0; text-align: {{ $crmAlign }};">{{ __('weekly_summary.mail.greeting', ['name' => $recipientName]) }}</div>

<div dir="{{ $crmDirection }}" style="{{ $crmBlock }}">{{ __('weekly_summary.mail.intro', $period) }}</div>

<div class="table" dir="{{ $crmDirection }}">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $crmDirection }}">
@foreach ($stats as $label => $value)
<tr><td style="{{ $crmCell }}">{{ $label }}</td><td style="{{ $crmNumber }}"><strong>{{ $value }}</strong></td></tr>
@endforeach
</table>
</div>

<x-mail::button :url="$boardUrl">
{{ __('weekly_summary.mail.open_board') }}
</x-mail::button>

<div dir="{{ $crmDirection }}" style="{{ $crmHeading }}">{{ __('weekly_summary.sections.assignees') }}</div>

@if ($assignees === [])
<div dir="{{ $crmDirection }}" style="{{ $crmMuted }}">{{ __('weekly_summary.empty.assignees') }}</div>
@else
<div class="table" dir="{{ $crmDirection }}">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $crmDirection }}">
<tr><th style="{{ $crmCell }}">{{ __('weekly_summary.fields.assignee') }}</th><th style="{{ $crmNumber }}">{{ __('weekly_summary.fields.open') }}</th><th style="{{ $crmNumber }}">{{ __('weekly_summary.fields.overdue_short') }}</th><th style="{{ $crmNumber }}">{{ __('weekly_summary.fields.completed_short') }}</th></tr>
@foreach ($assignees as $row)
<tr><td style="{{ $crmCell }}">{{ $row['name'] }}</td><td style="{{ $crmNumber }}">{{ $row['open'] }}</td><td style="{{ $crmNumber }}">{{ $row['overdue'] }}</td><td style="{{ $crmNumber }}">{{ $row['completed'] }}</td></tr>
@endforeach
</table>
</div>
@endif

@if ($assigneesMore !== null)
<div dir="{{ $crmDirection }}" style="{{ $crmMuted }}">{{ $assigneesMore }}</div>

@endif
@foreach ($lists as $crmList)
<div dir="{{ $crmDirection }}" style="{{ $crmHeading }}">{{ $crmList['heading'] }}</div>

@if ($crmList['lines'] === [])
<div dir="{{ $crmDirection }}" style="{{ $crmMuted }}">{{ $crmList['empty'] }}</div>

@else
<ul dir="{{ $crmDirection }}" style="margin: 0 0 1em 0; padding-inline-start: 1.25em; text-align: {{ $crmAlign }};">
@foreach ($crmList['lines'] as $crmLine)
<li style="font-size: 15px; line-height: 1.5em; margin: 0 0 0.5em 0;">@if ($crmLine['url'] !== null)<a href="{{ $crmLine['url'] }}"><strong>{{ $crmLine['title'] }}</strong></a>@else<strong>{{ $crmLine['title'] }}</strong>@endif — {{ $crmLine['assignee'] }}<br><span style="color: #71717a; font-size: 14px;">{{ $crmLine['detail'] }}</span></li>
@endforeach
</ul>

@if ($crmList['more'] !== null)
<div dir="{{ $crmDirection }}" style="{{ $crmMuted }}">{{ $crmList['more'] }}</div>

@endif
@endif
@endforeach
@if ($health !== null)
<div dir="{{ $crmDirection }}" style="{{ $crmHeading }}">{{ __('weekly_summary.sections.health') }}</div>

<div dir="{{ $crmDirection }}" style="{{ $crmBlock }}">{{ $health['backup'] }}</div>

@if ($health['backup_problem'] !== null)
<div dir="{{ $crmDirection }}" style="color: #b91c1c; font-size: 16px; line-height: 1.5em; margin: 0 0 1em 0; text-align: {{ $crmAlign }};"><strong>{{ $health['backup_problem'] }}</strong></div>

@endif
<div class="table" dir="{{ $crmDirection }}">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $crmDirection }}">
@foreach ($health['rows'] as $label => $value)
<tr><td style="{{ $crmCell }}">{{ $label }}</td><td style="{{ $crmNumber }}"><strong>{{ $value }}</strong></td></tr>
@endforeach
</table>
</div>

<div dir="{{ $crmDirection }}" style="{{ $crmMuted }}">{{ __('weekly_summary.mail.health_hint') }}</div>

<x-mail::button :url="$health['url']">
{{ __('backups.notifications.open') }}
</x-mail::button>

@endif
<div dir="{{ $crmDirection }}" style="{{ $crmMuted }}">{{ __('weekly_summary.mail.preferences_hint') }}</div>
</x-mail::message>
