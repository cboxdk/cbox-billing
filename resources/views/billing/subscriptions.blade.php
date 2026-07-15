@extends('layouts.app')
@section('title', 'Subscriptions')
@section('crumb', 'Subscriptions')

@php
    use App\Billing\BillingMetrics;
    $statusPill = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning', 'canceled' => 'muted'];
    $statusLabel = ['active' => 'active', 'trialing' => 'trial', 'past_due' => 'past due', 'canceled' => 'canceled'];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Subscriptions</h1>
            <p class="cbx-page-desc" style="font-size:13px">182 active · 14 trials · 3 past due</p>
        </div>
        <button class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) New subscription</button>
    </header>

    <div class="cbx-tabs" style="min-height:40px;padding:4px 8px">
        <nav style="display:flex;flex:1;align-items:center;gap:2px">
            <a class="cbx-tab cbx-tab--active" href="#" style="padding:4px 9px">Active<span class="cbx-tab-count">182</span></a>
            <a class="cbx-tab" href="#" style="padding:4px 9px">Trials<span class="cbx-tab-count">14</span></a>
            <a class="cbx-tab" href="#" style="padding:4px 9px">Past due<span class="cbx-tab-count">3</span></a>
            <a class="cbx-tab" href="#" style="padding:4px 9px">Canceled</a>
        </nav>
    </div>

    <div class="filters">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input placeholder="Filter subscriptions…"><kbd class="k">F</kbd></div>
        <button class="fchip">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) Plan</button>
        <button class="fchip set">Status: active @include('partials.icon', ['name' => 'chevron-down', 'size' => 12, 'sw' => 1.7])</button>
        <button class="fchip">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) Renews</button>
        <span style="margin-left:auto" class="num mut">{{ count($subscriptions) }} of 182</span>
    </div>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Customer</th><th style="width:120px">Plan</th><th class="right" style="width:130px">MRR</th><th style="width:110px">Status</th><th style="width:120px">Renews</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @foreach ($subscriptions as $sub)
                    <tr>
                        <td><span style="display:flex;align-items:center;gap:10px"><span class="avatar-sm">{{ $sub['ini'] }}</span><span><span style="display:block;font-weight:500">{{ $sub['org'] }}</span><span class="num mut" style="display:block;font-size:11px">since {{ $sub['started'] }}</span></span></span></td>
                        <td>{{ $sub['plan'] }}</td>
                        <td class="right num">{{ BillingMetrics::formatMinor($sub['minor']) }}</td>
                        <td>
                            @php($v = $statusPill[$sub['status']] ?? 'muted')
                            <span class="cbx-pill cbx-pill--{{ $v }}"><span class="dot"></span>{{ $statusLabel[$sub['status']] ?? $sub['status'] }}</span>
                        </td>
                        <td class="num mut">{{ $sub['renews'] }}</td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
