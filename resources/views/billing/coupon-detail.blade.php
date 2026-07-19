@extends('layouts.app')
@section('title', $coupon['code'] ?? 'Coupon')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Coupons', 'href' => route('billing.coupons')],
        ['label' => $coupon['code'] ?? 'Coupon'],
    ]" />
@endsection

@php($c = $coupon)

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.coupons')" label="Back to coupons" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title num" style="font-size:20px">{{ $c['code'] }}
                @include('billing.partials.coupon-status', ['status' => $c['status']])
            </h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $c['name'] ?: 'Discount coupon' }} · {{ $c['discount'] }} · {{ $c['duration'] }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="{{ route('billing.coupons.edit', $c['id']) }}" class="cbx-btn cbx-btn--sm">Edit</a>
            @if ($c['archived'])
                <form method="POST" action="{{ route('billing.coupons.unarchive', $c['id']) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Reinstate</button></form>
            @else
                <form method="POST" action="{{ route('billing.coupons.archive', $c['id']) }}" style="margin:0"
                      data-confirm="Archive {{ $c['code'] }}? It stops redeeming for new customers; existing discounts keep applying." data-confirm-title="Archive coupon?" data-confirm-label="Archive" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Archive</button></form>
            @endif
            @if ($c['times_redeemed'] === 0)
                <form method="POST" action="{{ route('billing.coupons.destroy', $c['id']) }}" style="margin:0"
                      data-confirm="Delete {{ $c['code'] }}? This cannot be undone." data-confirm-title="Delete coupon?" data-confirm-label="Delete" data-confirm-variant="destructive">
                    @csrf @method('DELETE')
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Delete</button>
                </form>
            @endif
        </div>
    </header>

    <div class="cbx-grid-3">
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Times redeemed</div><div class="num" style="font-size:24px;font-weight:600">{{ number_format($c['times_redeemed']) }}</div></section>
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Redemption limit</div><div class="num" style="font-size:24px;font-weight:600">{{ $c['max_redemptions'] !== null ? number_format($c['max_redemptions']) : '∞' }}</div></section>
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Expires</div><div class="num" style="font-size:24px;font-weight:600">{{ $c['redeem_by'] ?? '—' }}</div></section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Definition</h2></header>
        <div style="padding:8px 20px 18px">
            <dl class="cbx-grid-2" style="gap:12px 24px;margin:0">
                <div><dt class="mut" style="font-size:12px">Discount</dt><dd style="margin:2px 0 0">{{ $c['discount'] }}</dd></div>
                <div><dt class="mut" style="font-size:12px">Duration</dt><dd style="margin:2px 0 0">{{ $c['duration'] }}</dd></div>
                <div><dt class="mut" style="font-size:12px">Applies to</dt><dd style="margin:2px 0 0">{{ $c['scope'] }}</dd></div>
                <div><dt class="mut" style="font-size:12px">Per-customer limit</dt><dd style="margin:2px 0 0">{{ $c['max_redemptions_per_customer'] !== null ? number_format($c['max_redemptions_per_customer']) : 'Unlimited' }}</dd></div>
            </dl>
            @if (!empty($c['plan_keys']))
                <div style="margin-top:14px"><dt class="mut" style="font-size:12px">Plans</dt>
                    <dd style="margin:4px 0 0;display:flex;gap:6px;flex-wrap:wrap">
                        @foreach ($c['plan_keys'] as $key)<span class="cbx-pill cbx-pill--muted num">{{ $key }}</span>@endforeach
                    </dd>
                </div>
            @endif
        </div>
    </section>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Redemptions</h2><span class="mut num" style="font-size:12px">{{ $c['redemptions_shown'] }} shown</span></header>
        <table class="tbl">
            <thead><tr><th>Organization</th><th style="width:160px">Subscription</th><th style="width:170px">Redeemed</th></tr></thead>
            <tbody>
                @forelse ($c['redemptions'] as $redemption)
                    <tr>
                        <td><a class="cbx-link num" href="{{ route('billing.customers.show', $redemption['organization_id']) }}">{{ $redemption['organization_id'] }}</a></td>
                        <td class="num">@if ($redemption['subscription_id'] !== null)<a class="cbx-link" href="{{ route('billing.subscriptions.show', $redemption['subscription_id']) }}">#{{ $redemption['subscription_id'] }}</a>@else<span class="mut">—</span>@endif</td>
                        <td class="num mut">{{ $redemption['redeemed_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'receipt', 'size' => 18, 'sw' => 1.7])</div><h3>Not redeemed yet.</h3><p>This coupon has no redemptions. It applies once a customer redeems it at checkout or subscribe.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
