@extends('layouts.app')
@section('title', 'Catalog')
@section('crumb', 'Catalog')

@php
    use App\Billing\Support\MoneyFormatter;
    $overagePill = ['bill' => 'info', 'block' => 'muted'];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Catalog</h1>
            <p class="cbx-page-desc" style="font-size:13px">Products, plans, per-currency prices and metered entitlements</p>
        </div>
    </header>

    @foreach ($products as $product)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div><h2 class="cbx-panel-title" style="font-size:14px">{{ $product['name'] }}</h2>@if($product['description'])<p class="cbx-panel-desc" style="font-size:12px">{{ $product['description'] }}</p>@endif</div>
                <span class="cbx-pill cbx-pill--muted">{{ count($product['plans']) }} plans</span>
            </header>
            <table class="tbl">
                <thead><tr><th style="width:130px">Plan</th><th>Prices</th><th>Included</th><th>Entitlements</th><th style="width:80px">Status</th></tr></thead>
                <tbody>
                    @foreach ($product['plans'] as $plan)
                        <tr>
                            <td>
                                <span style="display:block;font-weight:600">{{ $plan['name'] }}</span>
                                <span class="num mut" style="font-size:11px">per {{ $plan['interval'] }}</span>
                            </td>
                            <td>
                                @foreach ($plan['prices'] as $price)
                                    <span class="num" style="display:inline-block;margin-right:10px">{{ MoneyFormatter::minor($price['minor'], $price['currency']) }}</span>
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
                            <td>@if($plan['active'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>active</span>@else<span class="cbx-pill cbx-pill--muted">off</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
</div>
@endsection
