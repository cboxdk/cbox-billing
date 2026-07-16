@props(['rows' => [], 'total' => null])
{{-- A bordered key/value summary block. `rows` is an ordered [label => value] map; an
     optional `total` [label => value] is emphasised as the final row. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 4px; border:1px solid #e5ded2; border-radius:10px; overflow:hidden;">
    @foreach ($rows as $label => $value)
        <tr>
            <td style="padding:11px 16px; border-bottom:1px solid #efe9df; font-family:'Helvetica Neue',Arial,sans-serif; font-size:13px; color:#6b6560;">{{ $label }}</td>
            <td align="right" style="padding:11px 16px; border-bottom:1px solid #efe9df; font-family:'Helvetica Neue',Arial,sans-serif; font-size:14px; font-weight:600; color:#1a1714;">{{ $value }}</td>
        </tr>
    @endforeach
    @if ($total)
        @foreach ($total as $label => $value)
            <tr>
                <td style="padding:13px 16px; background:#faf7f2; font-family:'Helvetica Neue',Arial,sans-serif; font-size:13px; font-weight:600; color:#1a1714;">{{ $label }}</td>
                <td align="right" style="padding:13px 16px; background:#faf7f2; font-family:'Helvetica Neue',Arial,sans-serif; font-size:17px; font-weight:700; color:#1a1714;">{{ $value }}</td>
            </tr>
        @endforeach
    @endif
</table>
