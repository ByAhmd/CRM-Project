{{--
    Published from Laravel's mail views (decisions D-5, D-10).

    The reading direction follows the locale the message is rendered in (the
    recipient's, applied by the notification or mailable): <html>, <body> and
    every layout table carry dir, and a right-to-left message aligns its text
    to the right. The theme inlines "text-align: left" on paragraphs and
    headings, so the head style overrides it for the clients that honour
    head styles, and the published views set the alignment inline where the
    CRM writes its own blocks.
--}}
@php
    $crmDirection = __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr';
    $crmAlign = $crmDirection === 'rtl' ? 'right' : 'left';
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $crmDirection }}">
<head>
<title>{{ __('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}

.content-cell p,
.content-cell h1,
.content-cell h2,
.content-cell h3,
.content-cell ul,
.content-cell ol,
.content-cell blockquote {
text-align: {{ $crmAlign }} !important;
}

.footer .content-cell p {
text-align: center !important;
}
</style>
{!! $head ?? '' !!}
</head>
<body dir="{{ $crmDirection }}">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $crmDirection }}">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $crmDirection }}">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $crmDirection }}">
<!-- Body content -->
<tr>
<td class="content-cell" dir="{{ $crmDirection }}" align="{{ $crmAlign }}">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
