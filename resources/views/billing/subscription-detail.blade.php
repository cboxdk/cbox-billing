@extends('layouts.app')
@section('title', 'Subscription')
@section('crumb', 'Subscription')

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning', 'canceled' => 'muted'];
    $statusLabel = ['active' => 'active', 'trialing' => 'trial', 'past_due' => 'past due', 'canceled' => 'canceled'];
    $s = $subscription;
    $overagePill = ['bill' => 'info', 'block' => 'muted'];
@endphp

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.subscriptions') }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to subscriptions</a>

    <header class="cbx-page-header">
        <div style="display:flex;align-items:center;gap:12px">
            <span class="avatar-sm" style="width:36px;height:36px;font-size:13px">{{ $s['ini'] }}</span>
            <div>
                <h1 class="cbx-page-title" style="font-size:20px">{{ $s['org'] }}</h1>
                <p class="cbx-page-desc" style="font-size:13px">{{ $s['plan'] }} · {{ MoneyFormatter::minor($s['minor'], $s['currency']) }} / mo</p>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            @php($v = $statusPill[$s['status']] ?? 'muted')
            <span class="cbx-pill cbx-pill--{{ $v }}"><span class="dot"></span>{{ $statusLabel[$s['status']] ?? $s['status'] }}</span>
            <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.customers.show', $s['org_id']) }}">View customer</a>
        </div>
    </header>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Plan &amp; period</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Plan</dt><dd>{{ $s['plan'] }} · {{ $s['interval'] }}ly</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>MRR</dt><dd class="num">{{ MoneyFormatter::minor($s['minor'], $s['currency']) }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Seats</dt><dd class="num">{{ $s['seats'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Current period</dt><dd class="num">{{ $s['period_start'] }} → {{ $s['period_end'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Renewal</dt><dd class="num">{{ $s['renews'] }}</dd></div>
            </dl>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Included credits</h2></header>
            <table class="tbl">
                <thead><tr><th>Pool</th><th>Cadence</th><th class="right">Amount</th></tr></thead>
                <tbody>
                    @forelse ($s['credits'] as $credit)
                        <tr><td style="font-weight:500">{{ $credit['pool'] }}</td><td class="mut">{{ $credit['cadence'] }}</td><td class="right num">{{ number_format($credit['amount']) }} {{ $credit['denomination'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="mut" style="padding:16px;text-align:center">No credit grants.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Metered entitlements</h2></header>
        <table class="tbl">
            <thead><tr><th>Meter</th><th class="right" style="width:160px">Allowance</th><th style="width:120px">Overage</th><th style="width:100px">Status</th></tr></thead>
            <tbody>
                @foreach ($s['entitlements'] as $ent)
                    <tr>
                        <td style="font-weight:500">{{ $ent['meter'] }}</td>
                        <td class="right num">@if(!$ent['enabled'])—@elseif($ent['unlimited'])unlimited @else {{ number_format($ent['allowance']) }} {{ $ent['unit'] }}@endif</td>
                        <td><span class="cbx-pill cbx-pill--{{ $overagePill[$ent['overage']] ?? 'muted' }}">{{ $ent['overage'] }}</span></td>
                        <td>@if($ent['enabled'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>on</span>@else<span class="cbx-pill cbx-pill--muted">off</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
