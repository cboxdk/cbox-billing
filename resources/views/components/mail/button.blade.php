@props(['url' => '#', 'color' => '#2743b3'])
{{-- A primary call-to-action button, table-wrapped for email-client reliability. --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0 6px;">
    <tr>
        <td style="border-radius:9px; background:{{ $color }};">
            <a href="{{ $url }}" style="display:inline-block; padding:12px 22px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:9px;">{{ $slot }}</a>
        </td>
    </tr>
</table>
