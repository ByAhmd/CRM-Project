{{--
    Published from Laravel's notification mail view (decisions D-5, D-10).

    Every fixed line comes from lang/email.php in the locale the notification
    is rendered in (the recipient's), never from the framework's JSON keys, and
    each line the notification writes sits in its own block with the paragraph
    typography, the direction and the alignment of that locale, so an Arabic
    message reads right to left in every client. Lines are escaped here; a
    record name holding markdown characters stays literal text.
--}}
@php
    $crmDirection = __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr';
    $crmAlign = $crmDirection === 'rtl' ? 'right' : 'left';
    $crmBlock = "font-size: 16px; line-height: 1.5em; margin: 0 0 1em 0; text-align: {$crmAlign};";
@endphp
<x-mail::message>
{{-- Greeting --}}
<div dir="{{ $crmDirection }}" style="color: #18181b; font-size: 18px; font-weight: bold; margin: 0 0 1em 0; text-align: {{ $crmAlign }};">{{ ! empty($greeting) ? $greeting : ($level === 'error' ? __('email.mail.whoops') : __('email.mail.hello')) }}</div>

{{-- Intro Lines --}}
@foreach ($introLines as $line)
<div dir="{{ $crmDirection }}" style="{{ $crmBlock }}">{{ $line }}</div>

@endforeach
{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
<div dir="{{ $crmDirection }}" style="{{ $crmBlock }}">{{ $line }}</div>

@endforeach
{{-- Salutation --}}
<div dir="{{ $crmDirection }}" style="{{ $crmBlock }}">{!! ! empty($salutation) ? nl2br(e($salutation)) : e(__('email.mail.regards')).'<br>'.e(__('app.name')) !!}</div>

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
<div dir="{{ $crmDirection }}" style="font-size: 12px; line-height: 1.5em; margin: 0; text-align: {{ $crmAlign }};">{{ __('email.mail.action_fallback', ['action' => $actionText]) }} <span class="break-all" dir="ltr"><a href="{{ $actionUrl }}">{{ $displayableActionUrl }}</a></span></div>
</x-slot:subcopy>
@endisset
</x-mail::message>
