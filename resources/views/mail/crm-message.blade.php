{{--
    A templated message to a contact or a lead (decision D-10).

    The body is plain text already rendered by EmailTemplateRenderer with raw
    values; it is escaped HERE and its line breaks are kept with nl2br. Every
    line that carries a user-supplied value — greeting, body, reply hint,
    footer — sits inside its own HTML block so CommonMark never re-interprets
    it: a body line that starts with a list marker or a heading mark, or a
    name that contains `*` or `_` pairs, stays literal text. Each block
    carries the paragraph typography of Laravel's mail theme explicitly,
    because the theme inlines its `p` rules only on real paragraphs. Every
    fixed string comes from lang/email.php in the recipient's locale, and the
    fixed lines take that locale's direction and alignment (the published mail
    layout sets dir on the document); the body keeps dir="auto" so a message
    written in the other language still reads in its own order.
--}}
@php
    $crmDirection = __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr';
    $crmAlign = $crmDirection === 'rtl' ? 'right' : 'left';
@endphp
<x-mail::message :brand="$organisationName">
<div dir="{{ $crmDirection }}" style="font-size: 16px; line-height: 1.5em; margin: 0 0 1em 0; text-align: {{ $crmAlign }};">{{ __('email.mail.greeting', ['name' => $recipientName]) }}</div>

<div dir="auto" style="white-space: normal; font-size: 16px; line-height: 1.5em; margin: 0 0 1em 0; text-align: start;">{!! nl2br(e($messageBody)) !!}</div>

<div dir="{{ $crmDirection }}" style="font-size: 16px; line-height: 1.5em; margin: 0 0 1em 0; text-align: {{ $crmAlign }};">{{ __('email.mail.reply_hint', ['name' => $replyToName]) }}</div>

<div dir="{{ $crmDirection }}" style="font-size: 16px; line-height: 1.5em; margin: 0; text-align: {{ $crmAlign }};">{{ __('email.mail.footer', ['organisation' => $organisationName]) }}</div>
</x-mail::message>
