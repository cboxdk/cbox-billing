@extends('layouts.app')
@section('title', 'Dashboard')
@section('crumb', 'Dashboard')

@php
    use App\Billing\Support\MoneyFormatter;
    $line = $revenue->lineFor($primaryCurrency);
    $churnPct = number_format($metrics->churnRate() * 100, 2, ',', '.');
    $statusPill = ['paid' => 'success', 'open' => 'warning', 'draft' => 'muted'];
    $activeBook = $counts['active'] + $counts['past_due'];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Dashboard</h1>
            <p class="cbx-page-desc" style="font-size:13px">Recurring revenue across the seller book · {{ $primaryCurrency }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="cbx-pill cbx-pill--success"><span class="dot"></span>live data</span>
            <a class="cbx-btn cbx-btn--primary cbx-btn--sm" href="{{ route('billing.subscriptions') }}">@include('partials.icon', ['name' => 'repeat', 'size' => 14, 'sw' => 1.7]) Subscriptions</a>
        </div>
    </header>

    {{-- Stat strip — figures computed by the cboxdk/laravel-billing engine over real rows --}}
    <div class="stats">
        <div>
            <p class="lbl">MRR</p>
            <p class="val">{{ $line ? MoneyFormatter::money($line->mrr) : '—' }}</p>
            <span class="delta mut num">ARR {{ $line ? MoneyFormatter::money($line->arr) : '—' }}</span>
        </div>
        <div>
            <p class="lbl">Active subscriptions</p>
            <p class="val">{{ $activeBook }}<span class="mut" style="font-size:13px;font-weight:400"> · {{ $counts['trialing'] }} trials</span></p>
            <span class="delta mut num">{{ $line->subscriptions ?? 0 }} billed · {{ $primaryCurrency }}</span>
        </div>
        <div>
            <p class="lbl">Logo churn</p>
            <p class="val">{{ $churnPct }}%</p>
            <span class="delta {{ $counts['canceled'] > 0 ? 'warn' : 'mut' }} num">{{ $counts['canceled'] }} canceled · 30 days</span>
        </div>
        <div>
            <p class="lbl">Outstanding</p>
            <p class="val">{{ MoneyFormatter::money($metrics->outstanding()) }}</p>
            <span class="delta mut num">{{ $metrics->openInvoiceCount() }} invoices open</span>
        </div>
    </div>

    {{-- Recent invoices --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Recent invoices</h2></div>
            <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.invoices') }}">View all @include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</a>
        </header>
        <table class="tbl">
            <thead><tr><th style="width:150px">Invoice</th><th>Customer</th><th style="width:90px">Date</th><th class="right" style="width:140px">Amount</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($recentInvoices as $inv)
                    <tr data-href="{{ route('billing.invoices.show', $inv['id']) }}" tabindex="0" role="link" aria-label="Open invoice {{ $inv['number'] }}">
                        <td class="num">{{ $inv['number'] }}</td>
                        <td><span style="display:flex;align-items:center;gap:8px"><span class="avatar-sm" style="width:20px;height:20px;font-size:8px">{{ $inv['ini'] }}</span>{{ $inv['org'] }}</span></td>
                        <td class="num mut">{{ $inv['date'] }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($inv['minor'], $inv['currency']) }}</td>
                        <td>
                            @php($v = $statusPill[$inv['status']] ?? 'muted')
                            <span class="cbx-pill cbx-pill--{{ $v }}">@if($inv['status'] !== 'draft')<span class="dot"></span>@endif{{ $inv['status'] }}</span>
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="mut" style="padding:20px;text-align:center">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Revenue by plan</h2><span class="cbx-pill cbx-pill--info">MRR · {{ $primaryCurrency }}</span></header>
            <table class="tbl">
                <tbody>
                    @forelse ($planBreakdown as $row)
                        <tr data-href="{{ route('billing.catalog') }}" tabindex="0" role="link" aria-label="View {{ $row['plan'] }} in catalog">
                            <td style="font-weight:500">{{ $row['plan'] }}</td>
                            <td class="num mut" style="width:80px">{{ $row['count'] }} subs</td>
                            <td class="right num">{{ MoneyFormatter::minor($row['minor'], $row['currency']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="mut" style="padding:20px;text-align:center">No active subscriptions.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Payment gateways</h2></header>
            @foreach ($gateways as $gateway)
                <a class="cbx-row" style="padding:11px 20px" href="{{ route('billing.settings') }}">
                    <span style="flex:1;font-size:13px;font-weight:500">{{ $gateway['name'] }}</span>
                    @if ($gateway['connected'])
                        <span class="cbx-pill cbx-pill--success"><span class="dot"></span>connected</span>
                    @else
                        <span class="cbx-pill cbx-pill--muted">{{ $gateway['mode'] }}</span>
                    @endif
                </a>
            @endforeach
        </section>
        {{-- Dashboard cards an installed plugin contributes via Console::dashboardCard(); empty otherwise. --}}
        @consoleSlot(\Cbox\Console\Kit\ConsoleManager::DASHBOARD_CARDS)
    </div>
</div>
@endsection
