@extends('layouts.app')
@section('title', $note->number)
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Credit notes', 'href' => route('billing.credit-notes')],
        ['label' => $note->number],
    ]" />
@endsection

@php
    use App\Billing\Support\Initials;
    use App\Billing\Support\MoneyFormatter;
    $c = $note->currency;
@endphp

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.credit-notes') }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to credit notes</a>

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title num" style="font-size:20px">{{ $note->number }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">Issued by {{ $note->seller }} · {{ $note->issued_at->format('Y-m-d') }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="cbx-pill cbx-pill--muted">{{ $note->kind }}</span>
            @if ($note->invoice)
                <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.invoices.show', $note->invoice->id) }}">Invoice {{ $note->invoice_number }}</a>
            @endif
            <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.customers.show', $note->organization_id) }}">View customer</a>
        </div>
    </header>

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Credited to</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Customer</dt><dd><span style="display:flex;align-items:center;gap:8px"><span class="avatar-sm" style="width:20px;height:20px;font-size:8px">{{ Initials::of($note->organization?->name ?? $note->organization_id) }}</span>{{ $note->organization?->name ?? $note->organization_id }}</span></dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Reverses invoice</dt><dd class="num">{{ $note->invoice_number }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Reason</dt><dd>{{ ucfirst(str_replace('_', ' ', $note->reason)) }}</dd></div>
            </dl>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Amounts</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Net</dt><dd class="num">−{{ MoneyFormatter::minor($note->net_minor, $c) }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Tax</dt><dd class="num">−{{ MoneyFormatter::minor($note->tax_minor, $c) }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0;border-top:1px solid var(--border)"><dt style="font-weight:600">Total credited</dt><dd class="num" style="font-weight:600">−{{ MoneyFormatter::minor($note->gross_minor, $c) }}</dd></div>
            </dl>
        </section>
    </div>

    @if ($note->lines->isNotEmpty())
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Reversed lines</h2></header>
            <table class="tbl">
                <thead><tr><th>Description</th><th class="right" style="width:80px">Qty</th><th class="right" style="width:150px">Net</th><th class="right" style="width:150px">Total</th></tr></thead>
                <tbody>
                    @foreach ($note->lines as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td class="right num">{{ $line->quantity }}</td>
                            <td class="right num">−{{ MoneyFormatter::minor($line->net_minor, $c) }}</td>
                            <td class="right num">−{{ MoneyFormatter::minor($line->gross_minor, $c) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection
