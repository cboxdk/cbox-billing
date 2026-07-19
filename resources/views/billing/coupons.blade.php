@extends('layouts.app')
@section('title', 'Coupons')
@section('crumb', 'Coupons')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Coupons</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $coupons->total() }} coupons · discounts and promo codes redeemable at checkout &amp; subscribe</p>
        </div>
        <a href="{{ route('billing.coupons.create') }}" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New coupon</a>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.coupons') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter coupons…" aria-label="Filter coupons"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.coupons') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $coupons->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Code</th><th style="width:130px">Discount</th><th style="width:170px">Duration</th><th style="width:120px">Redemptions</th><th style="width:110px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($coupons as $coupon)
                    <tr data-href="{{ route('billing.coupons.show', $coupon['id']) }}" tabindex="0" role="link" aria-label="Open coupon {{ $coupon['code'] }}">
                        <td>
                            <span class="num" style="display:block;font-weight:600">{{ $coupon['code'] }}</span>
                            @if($coupon['name'])<span class="mut" style="font-size:11px">{{ \Illuminate\Support\Str::limit($coupon['name'], 60) }}</span>@endif
                        </td>
                        <td>{{ $coupon['discount'] }}</td>
                        <td class="mut">{{ $coupon['duration'] }}</td>
                        <td class="num">{{ $coupon['redemptions'] }}</td>
                        <td>
                            @include('billing.partials.coupon-status', ['status' => $coupon['status']])
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No coupons match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'receipt', 'size' => 18, 'sw' => 1.7])</div><h3>No coupons yet.</h3><p>Create a coupon to offer a discount or promo code. <a href="{{ route('billing.coupons.create') }}" style="color:var(--primary)">New coupon</a>.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $coupons->links('partials.pagination') }}
</div>
@endsection
