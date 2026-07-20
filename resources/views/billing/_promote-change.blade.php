{{--
    One row of the promotion diff, rendered recursively (a plan's changed prices, a price's
    changed tiers) — $change is an App\Billing\Environments\Promotion\ValueObjects\ObjectChange
    and $depth is the nesting level (0 for a top-level object).
--}}
@php
    $depth = $depth ?? 0;
    $status = $change->status->value;
    $pill = $status === 'created' ? 'cbx-pill--success' : ($status === 'updated' ? 'cbx-pill--warning' : 'cbx-pill--muted');
    $glyph = $status === 'created' ? '+' : ($status === 'updated' ? '~' : '=');
@endphp
<div style="padding:8px 0 8px {{ 12 + $depth * 22 }}px;border-top:1px solid var(--border)">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="cbx-pill {{ $pill }}"><span class="dot"></span>{{ $glyph }} {{ $change->status->label() }}</span>
        <code style="font-size:12px">{{ $change->token() }}</code>
        @if ($change->label !== $change->naturalKey)
            <span class="mut" style="font-size:12px">{{ $change->label }}</span>
        @endif
    </div>

    @if (! empty($change->fieldChanges))
        <table class="tbl" style="margin:6px 0 2px;font-size:12px">
            <tbody>
                @foreach ($change->fieldChanges as $field)
                    <tr>
                        <td style="width:180px;color:var(--muted-foreground)">{{ $field->field }}</td>
                        <td style="color:var(--destructive)"><s>{{ $field->old }}</s></td>
                        <td style="width:16px;text-align:center;color:var(--muted-foreground)">→</td>
                        <td style="color:var(--success)">{{ $field->new }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

@foreach ($change->childChanges as $childChange)
    @include('billing._promote-change', ['change' => $childChange, 'depth' => $depth + 1])
@endforeach
