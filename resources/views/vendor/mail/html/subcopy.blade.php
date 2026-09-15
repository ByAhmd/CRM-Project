{{--
    Published from Laravel's mail views: the subcopy (the action-link fallback)
    follows the message direction.
--}}
<table class="subcopy" width="100%" cellpadding="0" cellspacing="0" role="presentation" dir="{{ __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr' }}">
<tr>
<td>
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
