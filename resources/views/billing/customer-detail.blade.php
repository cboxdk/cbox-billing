@extends('layouts.app')
@section('title', 'Customer')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Customers', 'href' => route('billing.customers')],
        ['label' => $customer['org'] ?? 'Customer'],
    ]" />
@endsection

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning', 'canceled' => 'muted', 'none' => 'muted'];
    $standingPill = ['good' => 'success', 'disputed' => 'warning', 'suspended' => 'destructive'];
    $invStatusPill = ['paid' => 'success', 'open' => 'warning', 'draft' => 'muted', 'void' => 'muted'];
    $c = $customer;
@endphp

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.customers') }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to customers</a>

    <header class="cbx-page-header">
        <div style="display:flex;align-items:center;gap:12px">
            <span class="avatar-sm" style="width:36px;height:36px;font-size:13px">{{ $c['ini'] }}</span>
            <div>
                <h1 class="cbx-page-title" style="font-size:20px">{{ $c['org'] }}</h1>
                <p class="cbx-page-desc num" style="font-size:13px">{{ $c['id'] }}</p>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="cbx-pill cbx-pill--{{ $standingPill[$c['standing']] ?? 'muted' }}">standing: {{ $c['standing'] }}</span>
        </div>
    </header>

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Account</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Billing email</dt><dd>{{ $c['billing_email'] ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Country</dt><dd>{{ $c['billing_country'] ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Tax ID</dt><dd>{{ $c['tax_id'] ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Currency</dt><dd class="num">{{ $c['currency'] }}</dd></div>
            </dl>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Subscription</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Plan</dt><dd>{{ $c['plan'] ?? 'No active subscription' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Status</dt><dd><span class="cbx-pill cbx-pill--{{ $statusPill[$c['status']] ?? 'muted' }}">{{ $c['status'] === 'none' ? 'no sub' : $c['status'] }}</span></dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>MRR</dt><dd class="num">{{ MoneyFormatter::minor($c['mrr'], $c['currency']) }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Outstanding</dt><dd class="num">{{ $c['outstanding_label'] }}</dd></div>
            </dl>
        </section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Invoices</h2></header>
        <table class="tbl">
            <thead><tr><th style="width:170px">Invoice</th><th style="width:110px">Date</th><th class="right" style="width:150px">Amount</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($c['invoices'] as $inv)
                    <tr data-href="{{ route('billing.invoices.show', $inv['id']) }}" tabindex="0" role="link" aria-label="Open invoice {{ $inv['number'] }}">
                        <td class="num">{{ $inv['number'] }}</td>
                        <td class="num mut">{{ $inv['date'] }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($inv['minor'], $inv['currency']) }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $invStatusPill[$inv['status']] ?? 'muted' }}">@if($inv['status'] !== 'draft')<span class="dot"></span>@endif{{ $inv['status'] }}</span></td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut" style="padding:20px;text-align:center">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
