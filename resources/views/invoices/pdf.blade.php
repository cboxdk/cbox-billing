@php
    use App\Billing\Support\MoneyFormatter;
    $c = $invoice->currency;
    $title = $isCreditNote ? 'Credit note' : 'Invoice';
    $sign = $isCreditNote ? -1 : 1;
    $fmt = fn (int $minor) => MoneyFormatter::minor($sign * $minor, $c);
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    body { font-family: Helvetica, Arial, sans-serif; color: #1a1a1a; font-size: 12px; margin: 0; padding: 0; }
    .wrap { padding: 40px 44px; }
    h1 { font-size: 22px; margin: 0 0 2px; letter-spacing: -0.02em; }
    .muted { color: #6b6b6b; }
    .row { width: 100%; }
    .row td { vertical-align: top; }
    .head td { padding-bottom: 22px; }
    .seller-name { font-size: 14px; font-weight: bold; }
    .meta { text-align: right; }
    .meta .num { font-size: 13px; font-weight: bold; }
    .status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; }
    .status-paid { background: #e6f4ea; color: #1e7e34; }
    .status-open { background: #fdf0e3; color: #b5691a; }
    .status-draft { background: #eee; color: #666; }
    .status-void { background: #eee; color: #666; }
    .parties td { padding: 0 0 20px; width: 50%; }
    .label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #8a8a8a; margin-bottom: 4px; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #8a8a8a; border-bottom: 1px solid #d8d8d8; padding: 8px 6px; }
    table.lines td { padding: 9px 6px; border-bottom: 1px solid #efefef; }
    .right { text-align: right; }
    .totals { width: 46%; margin-left: 54%; margin-top: 14px; }
    .totals td { padding: 5px 6px; }
    .totals .grand td { border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 14px; padding-top: 9px; }
    .foot { margin-top: 40px; padding-top: 14px; border-top: 1px solid #e4e4e4; font-size: 10px; color: #8a8a8a; }
</style>
</head>
<body>
<div class="wrap">
    <table class="row head">
        <tr>
            <td>
                <div class="seller-name">{{ $seller['legal_name'] }}</div>
                @if ($seller['registration_number'])<div class="muted">Reg. {{ $seller['registration_number'] }}</div>@endif
                @foreach ($seller['tax_registrations'] as $reg)
                    <div class="muted">VAT {{ $reg['country'] }} {{ $reg['number'] }}</div>
                @endforeach
            </td>
            <td class="meta">
                <h1>{{ $title }}</h1>
                <div class="num">{{ $invoice->number }}</div>
                <div style="margin-top:6px">
                    <span class="status status-{{ $invoice->status }}">{{ $invoice->status }}</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="row parties">
        <tr>
            <td>
                <div class="label">Billed to</div>
                <div><strong>{{ $invoice->organization->name }}</strong></div>
                @if ($invoice->organization->billing_email)<div class="muted">{{ $invoice->organization->billing_email }}</div>@endif
                @if ($invoice->organization->billing_country)<div class="muted">{{ $invoice->organization->billing_country }}</div>@endif
                @if ($invoice->organization->tax_id)<div class="muted">VAT {{ $invoice->organization->tax_id }}</div>@endif
            </td>
            <td>
                <div class="label">Details</div>
                <div><span class="muted">Issued</span> &nbsp; {{ $invoice->issued_at?->format('Y-m-d') ?? '—' }}</div>
                <div><span class="muted">Due</span> &nbsp; {{ $invoice->due_at?->format('Y-m-d') ?? '—' }}</div>
                @if ($invoice->paid_at)<div><span class="muted">Paid</span> &nbsp; {{ $invoice->paid_at->format('Y-m-d') }}</div>@endif
                <div><span class="muted">Currency</span> &nbsp; {{ $invoice->currency }}</div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right" style="width:60px">Qty</th>
                <th class="right" style="width:110px">Unit</th>
                <th class="right" style="width:120px">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $line->quantity }}</td>
                    <td class="right">{{ $fmt($line->unit_minor) }}</td>
                    <td class="right">{{ $fmt($line->amount_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Subtotal</td><td class="right">{{ $fmt($invoice->subtotal_minor) }}</td></tr>
        <tr><td class="muted">Tax</td><td class="right">{{ $fmt($invoice->tax_minor) }}</td></tr>
        <tr class="grand"><td>{{ $isCreditNote ? 'Total credited' : 'Total due' }}</td><td class="right">{{ $fmt($invoice->total_minor) }}</td></tr>
    </table>

    <div class="foot">
        {{ $seller['legal_name'] }}@if ($seller['establishment']) · {{ $seller['establishment'] }}@endif · {{ $invoice->number }}
    </div>
</div>
</body>
</html>
