@extends('layouts.app')
@section('title', 'Products')
@section('crumb', 'Products')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Products</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $products->total() }} products · the sellable groupings your plans hang off</p>
        </div>
        <a href="{{ route('billing.products.create') }}" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New product</a>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.products') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter products…" aria-label="Filter products"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.products') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $products->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Product</th><th style="width:120px">Plans</th><th style="width:120px">Active</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($products as $product)
                    <tr data-href="{{ route('billing.products.show', $product['id']) }}" tabindex="0" role="link" aria-label="Open product {{ $product['name'] }}">
                        <td>
                            <span style="display:block;font-weight:600">{{ $product['name'] }}</span>
                            <span class="num mut" style="font-size:11px">{{ $product['key'] }}@if($product['description']) · {{ \Illuminate\Support\Str::limit($product['description'], 60) }}@endif</span>
                        </td>
                        <td class="num">{{ number_format($product['plans']) }}</td>
                        <td class="num mut">{{ number_format($product['active_plans']) }}</td>
                        <td>
                            @if ($product['archived'])
                                <span class="cbx-pill cbx-pill--muted">archived</span>
                            @else
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>live</span>
                            @endif
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No products match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'box', 'size' => 18, 'sw' => 1.7])</div><h3>No products yet.</h3><p>Create a product to group the plans you sell. <a href="{{ route('billing.products.create') }}" style="color:var(--primary)">New product</a>.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $products->links('partials.pagination') }}
</div>
@endsection
