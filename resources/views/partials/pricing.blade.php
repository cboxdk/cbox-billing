{{--
    Public-style pricing partial (#55). Renders catalog-driven plan cards for a single
    currency — the same shape a public marketing page would use, embeddable anywhere.

        @include('partials.pricing', [
            'plans'    => $plans,     // list of plan arrays (PricingReport comparison shape)
            'currency' => 'DKK',
            'meters'   => $meters,    // list of {key,name,unit}
            'includeLegacy' => false, // (optional) show grandfathered plans
        ])

    Deny-by-default: a plan not priced in the currency is skipped rather than shown at a
    fabricated rate.
--}}
@php
    use App\Billing\Support\MoneyFormatter;
    $currency = $currency ?? 'DKK';
    $plans = $plans ?? [];
    $meters = $meters ?? [];
    $includeLegacy = $includeLegacy ?? false;
    $meterNames = collect($meters)->pluck('name', 'key')->all();
    $meterUnits = collect($meters)->pluck('unit', 'key')->all();
    $cards = collect($plans)->filter(fn ($p) => isset($p['prices'][$currency]) && ($includeLegacy || empty($p['legacy'])))->values();
@endphp

<div class="cbx-pricing" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px">
    @foreach ($cards as $plan)
        <div class="cbx-pricing-card" style="display:flex;flex-direction:column;border:1px solid var(--border);border-radius:12px;padding:18px;background:var(--card)">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                <span style="font-size:15px;font-weight:600">{{ $plan['name'] }}</span>
                @if (!empty($plan['legacy']))<span class="cbx-pill cbx-pill--muted">legacy</span>@endif
            </div>
            <div style="margin:10px 0 4px">
                <span class="num" style="font-size:24px;font-weight:700">{{ MoneyFormatter::minor($plan['prices'][$currency], $currency) }}</span>
                <span class="mut" style="font-size:12px">/ {{ $plan['interval'] }}</span>
            </div>
            <ul style="list-style:none;margin:12px 0 0;padding:0;flex:1">
                @foreach ($plan['entitlements'] as $meterKey => $ent)
                    @if (!empty($ent['included']))
                        <li style="display:flex;align-items:baseline;gap:8px;padding:5px 0;font-size:12.5px">
                            <span style="color:var(--success)">@include('partials.icon', ['name' => 'check', 'size' => 13, 'sw' => 2])</span>
                            <span>
                                @if (!empty($ent['unlimited']))
                                    Unlimited {{ strtolower($meterNames[$meterKey] ?? $meterKey) }}
                                @else
                                    {{ number_format($ent['allowance']) }} {{ $meterUnits[$meterKey] ?? strtolower($meterNames[$meterKey] ?? $meterKey) }}
                                @endif
                            </span>
                        </li>
                    @endif
                @endforeach
            </ul>
            @isset($checkoutRoute)
                <a class="cbx-btn cbx-btn--primary cbx-btn--sm" style="margin-top:14px" href="{{ $checkoutRoute($plan['key']) }}">Choose {{ $plan['name'] }}</a>
            @endisset
        </div>
    @endforeach
    @if ($cards->isEmpty())
        <p class="mut">No plans are priced in {{ $currency }}.</p>
    @endif
</div>
