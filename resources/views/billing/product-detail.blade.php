@extends('layouts.app')
@section('title', $product['name'] ?? 'Product')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Products', 'href' => route('billing.products')],
        ['label' => $product['name'] ?? 'Product'],
    ]" />
@endsection

@php($p = $product)

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.products')" label="Back to products" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $p['name'] }}
                @if ($p['archived'])<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">archived</span>@endif
            </h1>
            <p class="cbx-page-desc num" style="font-size:13px">{{ $p['key'] }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="{{ route('billing.products.edit', $p['id']) }}" class="cbx-btn cbx-btn--sm">Edit</a>
            @if ($p['archived'])
                <form method="POST" action="{{ route('billing.products.unarchive', $p['id']) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Reinstate</button></form>
            @else
                <form method="POST" action="{{ route('billing.products.archive', $p['id']) }}" style="margin:0"
                      data-confirm="Archive {{ $p['name'] }}? Its plans and their subscribers stay as-is; it is hidden from new-plan pickers." data-confirm-title="Archive product?" data-confirm-label="Archive" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Archive</button></form>
            @endif
            @if ($p['plan_count'] === 0)
                <form method="POST" action="{{ route('billing.products.destroy', $p['id']) }}" style="margin:0"
                      data-confirm="Delete {{ $p['name'] }}? This cannot be undone." data-confirm-title="Delete product?" data-confirm-label="Delete" data-confirm-variant="destructive">
                    @csrf @method('DELETE')
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Delete</button>
                </form>
            @endif
        </div>
    </header>

    @if ($p['description'])
        <p class="mut" style="font-size:13px;margin:-4px 0 0">{{ $p['description'] }}</p>
    @endif

    <div class="cbx-grid-3">
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Plans</div><div class="num" style="font-size:24px;font-weight:600">{{ number_format($p['plan_count']) }}</div></section>
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Active plans</div><div class="num" style="font-size:24px;font-weight:600">{{ number_format($p['active_plans']) }}</div></section>
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Serving subscribers</div><div class="num" style="font-size:24px;font-weight:600">{{ number_format($p['subscribers']) }}</div></section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">Plans</h2>
            <a href="{{ route('billing.plans.create') }}" class="cbx-btn cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7])New plan</a>
        </header>
        <table class="tbl">
            <thead><tr><th>Plan</th><th style="width:100px">Interval</th><th style="width:90px">Prices</th><th style="width:110px">Entitlements</th><th style="width:110px">Subscribers</th><th style="width:90px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($p['plans'] as $plan)
                    <tr data-href="{{ route('billing.plans.show', $plan['id']) }}" tabindex="0" role="link" aria-label="Open plan {{ $plan['name'] }}">
                        <td><span style="display:block;font-weight:500">{{ $plan['name'] }}</span><span class="num mut" style="font-size:11px">{{ $plan['key'] }}</span></td>
                        <td class="mut">per {{ $plan['interval'] }}</td>
                        <td class="num">{{ number_format($plan['prices']) }}</td>
                        <td class="num">{{ number_format($plan['entitlements']) }}</td>
                        <td class="num">{{ number_format($plan['subscribers']) }}</td>
                        <td>
                            @if ($plan['active'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>active</span>@else<span class="cbx-pill cbx-pill--muted">legacy</span>@endif
                            @if ($plan['retiring'])<span class="cbx-pill cbx-pill--warning">retiring</span>@endif
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'box', 'size' => 18, 'sw' => 1.7])</div><h3>No plans yet.</h3><p>Add a plan to price this product. <a href="{{ route('billing.plans.create') }}" class="cbx-link">New plan</a>.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
