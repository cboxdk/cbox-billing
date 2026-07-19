@extends('layouts.app')
@section('title', 'Plans & pricing')
@section('crumb', 'Plans & pricing')

@php
    use App\Billing\Support\MoneyFormatter;
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Plans &amp; pricing</h1>
            <p class="cbx-page-desc" style="font-size:13px">Catalog-driven plan comparison · per-currency prices &amp; entitlements</p>
        </div>
        <div class="filters" style="margin:0">
            @foreach ($currencies as $cur)
                <a class="fchip {{ $currency === $cur ? 'set' : '' }}" href="{{ route('billing.pricing', ['currency' => $cur]) }}">{{ $cur }}</a>
            @endforeach
        </div>
    </header>

    {{-- The public-style pricing cards (offered plans only) --}}
    @include('partials.pricing', ['plans' => $plans, 'currency' => $currency, 'meters' => $meters])

    {{-- The full comparison matrix: every plan (legacy marked) × every meter --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">Comparison</h2>
            <span class="num mut" style="font-size:11px">{{ $currency }}</span>
        </header>
        <table class="tbl">
            <thead>
                <tr>
                    <th style="width:200px">Feature</th>
                    @foreach ($plans as $plan)
                        <th class="right">
                            @if (!empty($plan['id']))<a class="cbx-link" href="{{ route('billing.plans.show', $plan['id']) }}">{{ $plan['name'] }}</a>@else{{ $plan['name'] }}@endif
                            @if ($plan['legacy'])<span class="cbx-pill cbx-pill--muted" style="margin-left:4px">legacy</span>@endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="mut">Price / {{ $plans[0]['interval'] ?? 'month' }}</td>
                    @foreach ($plans as $plan)
                        <td class="right num">
                            @if (isset($plan['prices'][$currency]))
                                {{ MoneyFormatter::minor($plan['prices'][$currency], $currency) }}
                            @else
                                <span class="mut">—</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
                @foreach ($meters as $meter)
                    <tr>
                        <td>{{ $meter['name'] }} <span class="mut" style="font-size:11px">({{ $meter['unit'] }})</span></td>
                        @foreach ($plans as $plan)
                            @php($ent = $plan['entitlements'][$meter['key']] ?? ['included' => false])
                            <td class="right num">
                                @if (!$ent['included'])
                                    <span class="mut">—</span>
                                @elseif ($ent['unlimited'])
                                    ∞
                                @else
                                    {{ number_format($ent['allowance']) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
