{{--
    Published from Laravel's mail views: the brand is the translated product
    name passed by message.blade.php, never the framework's logo image.
--}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
</tr>
