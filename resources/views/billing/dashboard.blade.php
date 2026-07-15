@extends('layouts.app')
@section('title', 'Dashboard')
@section('crumb', 'Dashboard')

@php
    use App\Billing\BillingMetrics;
    $dkk = $revenue->lineFor('DKK');
    $churnPct = number_format($metrics->churnRate() * 100, 2, ',', '.');
    $statusPill = ['paid' => 'success', 'open' => 'warning', 'draft' => 'muted'];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Dashboard</h1>
            <p class="cbx-page-desc" style="font-size:13px">Recurring revenue across Cbox Systems · this month</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button class="fchip set">Last 30 days @include('partials.icon', ['name' => 'chevron-down', 'size' => 12, 'sw' => 1.7])</button>
            <button class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) New subscription</button>
        </div>
    </header>

    {{-- Stat strip — figures computed by the cboxdk/laravel-billing engine --}}
    <div class="stats">
        <div>
            <p class="lbl">MRR</p>
            <p class="val">{{ $dkk ? BillingMetrics::format($dkk->mrr) : '—' }}</p>
            <span class="delta up">@include('partials.icon', ['name' => 'arrow-up-right', 'size' => 12, 'sw' => 1.7]) 6,2% vs last month</span>
        </div>
        <div>
            <p class="lbl">Active subscriptions</p>
            <p class="val">{{ $metrics->activeSubscriptions() }}<span class="mut" style="font-size:13px;font-weight:400"> · {{ $metrics->trials() }} trials</span></p>
            <span class="delta up">@include('partials.icon', ['name' => 'arrow-up-right', 'size' => 12, 'sw' => 1.7]) 9 net new · 7 days</span>
        </div>
        <div>
            <p class="lbl">Logo churn</p>
            <p class="val">{{ $churnPct }}%</p>
            <span class="delta warn num">3 canceled · 30 days</span>
        </div>
        <div>
            <p class="lbl">Outstanding</p>
            <p class="val">{{ BillingMetrics::format($metrics->outstanding()) }}</p>
            <span class="delta mut num">2 invoices open</span>
        </div>
    </div>

    {{-- Recent invoices --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Recent invoices</h2></div>
            <div style="display:flex;gap:6px;align-items:center">
                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>live</span>
                <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.invoices') }}">View all @include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</a>
            </div>
        </header>
        <table class="tbl">
            <thead><tr><th style="width:150px">Invoice</th><th>Customer</th><th style="width:90px">Date</th><th class="right" style="width:130px">Amount</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @foreach ($invoices as $inv)
                    <tr>
                        <td class="num">{{ $inv['number'] }}</td>
                        <td><span style="display:flex;align-items:center;gap:8px"><span class="avatar-sm" style="width:20px;height:20px;font-size:8px">{{ $inv['ini'] }}</span>{{ $inv['org'] }}</span></td>
                        <td class="num mut">{{ $inv['date'] }}</td>
                        <td class="right num">{{ BillingMetrics::formatMinor($inv['minor']) }}</td>
                        <td>
                            @php($v = $statusPill[$inv['status']] ?? 'muted')
                            <span class="cbx-pill cbx-pill--{{ $v }}">@if($inv['status'] !== 'draft')<span class="dot"></span>@endif{{ $inv['status'] }}</span>
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">This organization</h2><span class="cbx-pill cbx-pill--info">Team</span></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Plan</dt><dd>Team · DKK 1.240,00 / mo</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Seats</dt><dd>38 / 50</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Next invoice</dt><dd>2026-08-01 · DKK 1.240,00</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Payment method</dt><dd>Visa ···· 6411</dd></div>
            </dl>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Payment gateways</h2></header>
            <a class="cbx-row" style="padding:11px 20px" href="{{ route('billing.section', 'settings') }}"><span style="flex:1;font-size:13px;font-weight:500">Stripe</span><span class="cbx-pill cbx-pill--success"><span class="dot"></span>connected</span></a>
            <a class="cbx-row" style="padding:11px 20px" href="{{ route('billing.section', 'settings') }}"><span style="flex:1;font-size:13px;font-weight:500">Mollie</span><span class="cbx-pill cbx-pill--success"><span class="dot"></span>connected</span></a>
            <a class="cbx-row" style="padding:11px 20px" href="{{ route('billing.section', 'settings') }}"><span style="flex:1;font-size:13px;font-weight:500">Manual / bank transfer</span><span class="cbx-pill cbx-pill--muted">off</span></a>
        </section>
    </div>
</div>
@endsection
