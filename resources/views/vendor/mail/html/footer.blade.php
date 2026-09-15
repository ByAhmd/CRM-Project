{{--
    Published from Laravel's mail views: the footer table follows the message
    direction so the rights line reads in the recipient's language order.
--}}
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" dir="{{ __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr' }}">
<tr>
<td class="content-cell" align="center">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
</table>
</td>
</tr>
