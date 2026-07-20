@extends('layouts.app')
@section('title', $plan['name'] ?? 'Plan')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Catalog', 'href' => route('billing.catalog')],
        ['label' => 'Plans & pricing', 'href' => route('billing.pricing')],
        ['label' => $plan['name'] ?? 'Plan'],
    ]" />
@endsection

@php
    use App\Billing\Support\MoneyFormatter;
    $pl = $plan;
    $modelLabel = ['flat' => 'flat', 'per_unit' => 'per unit', 'graduated' => 'graduated', 'volume' => 'volume', 'package' => 'package', 'stairstep' => 'stairstep', 'mixed' => 'mixed'];
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.pricing')" label="Back to plans & pricing" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $pl['name'] }}
                @if ($pl['active'])<span class="cbx-pill cbx-pill--success" style="margin-left:6px"><span class="dot"></span>active</span>@else<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">legacy</span>@endif
                @if ($pl['retiring'])<span class="cbx-pill cbx-pill--warning" style="margin-left:2px">retiring {{ $pl['retires_at'] }}</span>@endif
            </h1>
            <p class="cbx-page-desc num" style="font-size:13px">{{ $pl['key'] }} · per {{ $pl['interval'] }}
                @if ($pl['product']) · <a href="{{ route('billing.products.show', $pl['product']['id']) }}" class="cbx-link">{{ $pl['product']['name'] }}</a>@endif
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="{{ route('billing.plans.edit', $pl['id']) }}" class="cbx-btn cbx-btn--sm">Edit</a>
            @if ($pl['active'])
                <form method="POST" action="{{ route('billing.plans.archive', $pl['id']) }}" style="margin:0"
                      data-confirm="Archive {{ $pl['name'] }}? It becomes legacy — closed to new signups. Current subscribers keep their grandfathered price." data-confirm-title="Archive plan?" data-confirm-label="Archive" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Archive</button></form>
            @else
                <form method="POST" action="{{ route('billing.plans.unarchive', $pl['id']) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Re-offer</button></form>
            @endif
            @if ($pl['total_subscribers'] === 0)
                <form method="POST" action="{{ route('billing.plans.destroy', $pl['id']) }}" style="margin:0"
                      data-confirm="Delete {{ $pl['name'] }} and its prices, entitlements and credit grants? This cannot be undone." data-confirm-title="Delete plan?" data-confirm-label="Delete" data-confirm-variant="destructive">
                    @csrf @method('DELETE')
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Delete</button>
                </form>
            @endif
        </div>
    </header>

    <div class="cbx-grid-3">
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Serving subscribers</div><div class="num" style="font-size:24px;font-weight:600">{{ number_format($pl['serving_subscribers']) }}</div><div class="mut" style="font-size:11px">{{ number_format($pl['total_subscribers']) }} total (incl. canceled)</div></section>
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Pricing model</div><div style="font-size:20px;font-weight:600">{{ $modelLabel[$pl['pricing_model']] ?? $pl['pricing_model'] }}</div><div class="mut" style="font-size:11px">seat/quantity pricing</div></section>
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Currencies</div><div class="num" style="font-size:20px;font-weight:600">{{ $pl['currencies'] ? implode(' · ', $pl['currencies']) : '—' }}</div><div class="mut" style="font-size:11px">grandfathered per subscriber</div></section>
    </div>

    @if ($pl['retiring'])
        <section class="cbx-panel" style="padding:14px 20px;border-left:3px solid var(--warning)">
            <div style="font-size:13px">Retiring <strong>{{ $pl['retires_at'] }}</strong>@if($pl['default_successor']) · default successor <a href="{{ route('billing.plans.show', $pl['default_successor']['id']) }}" class="cbx-link">{{ $pl['default_successor']['name'] }}</a>@else · <span style="color:var(--destructive)">no default (unresolved subscribers flagged)</span>@endif</div>
        </section>
    @endif

    {{-- Prices --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">Prices</h2>
            <a href="{{ route('billing.catalog.prices.create') }}" class="cbx-btn cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7])New price</a>
        </header>
        <table class="tbl">
            <thead><tr><th style="width:90px">Currency</th><th style="width:150px">Base</th><th style="width:110px">Model</th><th>Tiers</th><th style="width:150px"></th></tr></thead>
            <tbody>
                @forelse ($pl['prices'] as $price)
                    <tr>
                        <td class="num" style="font-weight:600">{{ $price['currency'] }}</td>
                        <td class="num">{{ MoneyFormatter::minor($price['minor'], $price['currency']) }}@if($price['tiered'])<span class="mut" style="font-size:10px"> base</span>@endif</td>
                        <td><span class="cbx-pill cbx-pill--{{ $price['tiered'] ? 'info' : 'muted' }}">{{ $modelLabel[$price['model']] ?? $price['model'] }}</span></td>
                        <td class="mut num" style="font-size:12px">
                            @if ($price['tiered'])
                                {{ count($price['tiers']) }} tiers @if ($price['package_size'])· packs of {{ number_format($price['package_size']) }}@endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="right">
                            <span style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end">
                                <a href="{{ route('billing.catalog.prices.edit', $price['id']) }}" class="cbx-btn cbx-btn--sm">Edit</a>
                                <form method="POST" action="{{ route('billing.catalog.prices.destroy', $price['id']) }}" style="margin:0"
                                      data-confirm="Remove the {{ $pl['name'] }} {{ $price['currency'] }} price? Refused if a serving subscriber still bills in {{ $price['currency'] }}." data-confirm-title="Remove price?" data-confirm-label="Remove" data-confirm-variant="destructive">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Remove</button>
                                </form>
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'wallet', 'size' => 18, 'sw' => 1.7])</div><h3>No prices yet.</h3><p>Add a per-currency price so this plan can be sold. <a href="{{ route('billing.catalog.prices.create') }}" style="color:var(--primary)">New price</a>.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Entitlements --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">Entitlements</h2>
            <a href="{{ route('billing.plans.entitlements.create', $pl['id']) }}" class="cbx-btn cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7])Add entitlement</a>
        </header>
        <table class="tbl">
            <thead><tr><th>Meter</th><th style="width:110px">Aggregation</th><th style="width:130px">Allowance</th><th style="width:90px">Overage</th><th style="width:150px"></th></tr></thead>
            <tbody>
                @forelse ($pl['entitlements'] as $ent)
                    <tr>
                        <td><span style="display:block;font-weight:500">{{ $ent['meter'] }}</span><span class="num mut" style="font-size:11px">{{ $ent['meter_key'] }}</span></td>
                        <td class="mut num" style="font-size:12px">{{ $ent['aggregation'] ?: '—' }}</td>
                        <td class="num">
                            @if (!$ent['enabled'])<span class="cbx-pill cbx-pill--muted">off</span>
                            @elseif ($ent['unlimited'])∞
                            @else{{ number_format($ent['allowance']) }} {{ $ent['unit'] }}@endif
                        </td>
                        <td>@if($ent['enabled'] && !$ent['unlimited'])<span class="cbx-pill cbx-pill--{{ $ent['overage'] === 'bill' ? 'info' : 'muted' }}">{{ $ent['overage'] }}</span>@else<span class="mut">—</span>@endif</td>
                        <td class="right">
                            <span style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end">
                                <a href="{{ route('billing.plans.entitlements.edit', [$pl['id'], $ent['id']]) }}" class="cbx-btn cbx-btn--sm">Edit</a>
                                <form method="POST" action="{{ route('billing.plans.entitlements.destroy', [$pl['id'], $ent['id']]) }}" style="margin:0"
                                      data-confirm="Remove the {{ $ent['meter'] }} entitlement? The meter reverts to deny-by-default for this plan." data-confirm-title="Remove entitlement?" data-confirm-label="Remove" data-confirm-variant="destructive">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Remove</button>
                                </form>
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'gauge', 'size' => 18, 'sw' => 1.7])</div><h3>No entitlements yet.</h3><p>Add a metered allowance per meter. <a href="{{ route('billing.plans.entitlements.create', $pl['id']) }}" style="color:var(--primary)">Add entitlement</a>.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Feature grants (boolean / config product gating) --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">Features</h2>
            <a href="{{ route('billing.plans.features.create', $pl['id']) }}" class="cbx-btn cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7])Add feature grant</a>
        </header>
        <table class="tbl">
            <thead><tr><th>Feature</th><th style="width:110px">Type</th><th style="width:130px">Grant</th><th style="width:120px">Value</th><th style="width:150px"></th></tr></thead>
            <tbody>
                @forelse ($pl['features'] as $ft)
                    <tr>
                        <td><span style="display:block;font-weight:500">{{ $ft['feature'] }}</span><span class="num mut" style="font-size:11px">{{ $ft['feature_key'] }}</span></td>
                        <td><span class="cbx-pill cbx-pill--{{ $ft['type'] === 'config' ? 'info' : 'muted' }}">{{ $ft['type'] }}</span></td>
                        <td>@if($ft['enabled'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>granted</span>@else<span class="cbx-pill cbx-pill--muted">off</span>@endif</td>
                        <td class="num">{{ $ft['value'] ?? '—' }}</td>
                        <td class="right">
                            <span style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end">
                                <a href="{{ route('billing.plans.features.edit', [$pl['id'], $ft['id']]) }}" class="cbx-btn cbx-btn--sm">Edit</a>
                                <form method="POST" action="{{ route('billing.plans.features.destroy', [$pl['id'], $ft['id']]) }}" style="margin:0"
                                      data-confirm="Remove the {{ $ft['feature'] }} grant? The feature reverts to deny-by-default for this plan." data-confirm-title="Remove feature grant?" data-confirm-label="Remove" data-confirm-variant="destructive">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Remove</button>
                                </form>
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>No feature grants yet.</h3><p>Grant a boolean/config feature with this plan. <a href="{{ route('billing.plans.features.create', $pl['id']) }}" style="color:var(--primary)">Add feature grant</a>.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Credit grants --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">Credit grants</h2>
            <a href="{{ route('billing.plans.credit-grants.create', $pl['id']) }}" class="cbx-btn cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7])Add credit grant</a>
        </header>
        <table class="tbl">
            <thead><tr><th style="width:120px">Pool</th><th style="width:90px">Kind</th><th style="width:100px">Cadence</th><th style="width:150px">Amount</th><th style="width:150px"></th></tr></thead>
            <tbody>
                @forelse ($pl['credit_grants'] as $grant)
                    <tr>
                        <td><span class="cbx-pill cbx-pill--muted">{{ $grant['pool'] }}</span></td>
                        <td class="mut">{{ $grant['kind'] }}</td>
                        <td class="mut">{{ $grant['cadence'] }}</td>
                        <td class="num">{{ number_format($grant['amount']) }} {{ $grant['denomination'] }}<span class="mut" style="font-size:11px"> · {{ $grant['amount_mode'] }}</span></td>
                        <td class="right">
                            <span style="display:inline-flex;gap:6px;align-items:center;justify-content:flex-end">
                                <a href="{{ route('billing.plans.credit-grants.edit', [$pl['id'], $grant['id']]) }}" class="cbx-btn cbx-btn--sm">Edit</a>
                                <form method="POST" action="{{ route('billing.plans.credit-grants.destroy', [$pl['id'], $grant['id']]) }}" style="margin:0"
                                      data-confirm="Remove this {{ $grant['pool'] }} credit grant? Nothing further vests from it; already-vested credits stand." data-confirm-title="Remove credit grant?" data-confirm-label="Remove" data-confirm-variant="destructive">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Remove</button>
                                </form>
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'wallet', 'size' => 18, 'sw' => 1.7])</div><h3>No credit grants yet.</h3><p>Grant recurring or one-time credits with this plan. <a href="{{ route('billing.plans.credit-grants.create', $pl['id']) }}" style="color:var(--primary)">Add credit grant</a>.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
