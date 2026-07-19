@extends('layouts.app')
@section('title', 'Catalog')
@section('crumb', 'Catalog')

@php
    use App\Billing\Support\MoneyFormatter;
    $overagePill = ['bill' => 'info', 'block' => 'muted'];
    // Engine PricingModel → console label. `flat` is the plain recurring amount; the rest
    // are engine v0.8 tiered/volume/graduated/package/stairstep models.
    $modelLabel = [
        'flat' => 'flat', 'per_unit' => 'per unit', 'graduated' => 'graduated',
        'volume' => 'volume', 'package' => 'package', 'stairstep' => 'stairstep', 'mixed' => 'mixed',
    ];
    $modelPill = [
        'flat' => 'muted', 'per_unit' => 'info', 'graduated' => 'info',
        'volume' => 'info', 'package' => 'info', 'stairstep' => 'info', 'mixed' => 'warning',
    ];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Catalog</h1>
            <p class="cbx-page-desc" style="font-size:13px">Products, plans, per-currency prices and metered entitlements</p>
        </div>
        <a href="{{ route('billing.catalog.prices.create') }}" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New price</a>
    </header>

    @include('partials.flash')

    @foreach ($products as $product)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div><h2 class="cbx-panel-title" style="font-size:14px">{{ $product['name'] }}</h2>@if($product['description'])<p class="cbx-panel-desc" style="font-size:12px">{{ $product['description'] }}</p>@endif</div>
                <span class="cbx-pill cbx-pill--muted">{{ count($product['plans']) }} plans</span>
            </header>
            <table class="tbl">
                <thead><tr><th style="width:130px">Plan</th><th style="width:110px">Model</th><th>Prices</th><th>Included</th><th>Entitlements</th><th style="width:80px">Status</th></tr></thead>
                <tbody>
                    @foreach ($product['plans'] as $plan)
                        @php($tieredPrices = array_values(array_filter($plan['prices'], fn ($p) => $p['tiered'])))
                        <tr @if($tieredPrices)style="border-bottom:0"@endif>
                            <td>
                                <span style="display:block;font-weight:600">{{ $plan['name'] }}</span>
                                <span class="num mut" style="font-size:11px">per {{ $plan['interval'] }}</span>
                            </td>
                            <td>
                                <span class="cbx-pill cbx-pill--{{ $modelPill[$plan['pricing_model']] ?? 'muted' }}">{{ $modelLabel[$plan['pricing_model']] ?? $plan['pricing_model'] }}</span>
                            </td>
                            <td>
                                @foreach ($plan['prices'] as $price)
                                    <a href="{{ route('billing.catalog.prices.edit', $price['id']) }}" class="num" style="display:inline-block;margin-right:10px;color:var(--foreground);text-decoration:none;border-bottom:1px dashed var(--border)" title="Edit price">{{ MoneyFormatter::minor($price['minor'], $price['currency']) }}@if($price['tiered'])<span class="mut" style="font-size:10px"> base</span>@endif</a>
                                @endforeach
                            </td>
                            <td class="num mut">
                                @foreach ($plan['credits'] as $credit)
                                    {{ number_format($credit['amount']) }} {{ $credit['denomination'] }}
                                @endforeach
                            </td>
                            <td>
                                @foreach ($plan['entitlements'] as $ent)
                                    <span class="cbx-pill {{ $ent['enabled'] ? 'cbx-pill--info' : 'cbx-pill--muted' }}" style="margin:1px 3px 1px 0">
                                        {{ $ent['meter'] }}: @if(!$ent['enabled'])off @elseif($ent['unlimited'])∞ @else {{ number_format($ent['allowance']) }} @endif
                                    </span>
                                @endforeach
                            </td>
                            <td>
                                @if($plan['active'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>active</span>@else<span class="cbx-pill cbx-pill--muted">off</span>@endif
                                @if($plan['retiring'])<span class="cbx-pill cbx-pill--warning" title="Retires {{ $plan['retires_at'] }}"><span class="dot"></span>retiring {{ $plan['retires_at'] }}</span>@endif
                            </td>
                        </tr>
                        {{-- Plan retirement authoring (ADR-0016): mark retiring (cutoff + default successor) or un-retire --}}
                        <tr style="cursor:default" onclick="event.stopPropagation()">
                            <td colspan="6" style="padding:2px 20px 12px">
                                @if ($plan['retiring'])
                                    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:12px;color:var(--muted-foreground)">
                                        <span>Retires <strong style="color:var(--foreground)">{{ $plan['retires_at'] }}</strong>@if($plan['default_successor']) · default successor <strong style="color:var(--foreground)">{{ $plan['default_successor'] }}</strong>@else · <span style="color:var(--destructive)">no default (unresolved subscribers flagged)</span>@endif</span>
                                        <form method="POST" action="{{ route('billing.catalog.plans.unretire', $plan['id']) }}" style="margin:0"
                                              data-confirm="Un-retire {{ $plan['name'] }}? It will no longer be scheduled to retire and subscribers stay on it." data-confirm-title="Un-retire plan?" data-confirm-label="Un-retire" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Un-retire</button></form>
                                    </div>
                                @else
                                    <form method="POST" action="{{ route('billing.catalog.plans.retire', $plan['id']) }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center"
                                          data-confirm="Mark {{ $plan['name'] }} as retiring? Subscribers must move to a successor or the default plan before their next renewal, or they are flagged unresolved." data-confirm-title="Retire this plan?" data-confirm-label="Mark retiring" data-confirm-variant="primary">
                                        @csrf
                                        <span class="mut" style="font-size:12px">Retire this plan</span>
                                        <input type="date" name="retires_at" required style="height:30px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 8px;font-family:var(--font-sans);font-size:12px">
                                        <select name="default_successor_plan_id" style="height:30px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 8px;font-family:var(--font-sans);font-size:12px">
                                            <option value="">Default successor — none</option>
                                            @foreach ($successorChoices as $choice)
                                                @if ($choice['id'] !== $plan['id'])<option value="{{ $choice['id'] }}">{{ $choice['name'] }}</option>@endif
                                            @endforeach
                                        </select>
                                        <button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">Mark retiring</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        {{-- Tiered pricing: render each tiered currency's bracket table (up-to / unit / flat), read from the engine PriceTier set --}}
                        @if ($tieredPrices)
                            <tr style="cursor:default" onclick="event.stopPropagation()">
                                <td colspan="6" style="padding:0 20px 14px">
                                    <div style="display:flex;flex-wrap:wrap;gap:16px">
                                        @foreach ($tieredPrices as $price)
                                            <div style="flex:1;min-width:260px;border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden">
                                                <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 12px;background:var(--secondary)">
                                                    <span class="num" style="font-weight:600">{{ $price['currency'] }}</span>
                                                    <span class="mut" style="font-size:11px">{{ $modelLabel[$price['model']] ?? $price['model'] }}@if($price['package_size']) · packs of {{ number_format($price['package_size']) }}@endif</span>
                                                </div>
                                                <table class="tbl" style="font-size:12px">
                                                    <thead><tr><th style="padding:6px 12px">Up to</th><th class="right" style="padding:6px 12px">Unit</th><th class="right" style="padding:6px 12px">Flat</th></tr></thead>
                                                    <tbody>
                                                        @foreach ($price['tiers'] as $tier)
                                                            <tr style="cursor:default">
                                                                <td class="num" style="padding:6px 12px">@if($tier['up_to'] === null)∞@else{{ number_format($tier['up_to']) }}@endif</td>
                                                                <td class="right num" style="padding:6px 12px">{{ MoneyFormatter::minor($tier['unit_minor'], $price['currency']) }}</td>
                                                                <td class="right num" style="padding:6px 12px">@if($tier['flat_minor'] === null)<span class="mut">—</span>@else{{ MoneyFormatter::minor($tier['flat_minor'], $price['currency']) }}@endif</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
</div>
@endsection
