@extends('layouts.app')
@section('title', 'Meters')
@section('crumb', 'Meters')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Meters</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $meters->total() }} metered dimensions · the aggregation the engine bills each meter with</p>
        </div>
        <a href="{{ route('billing.meters.create') }}" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New meter</a>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.meters') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter meters…" aria-label="Filter meters"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.meters') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $meters->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Meter</th><th style="width:110px">Unit</th><th style="width:130px">Aggregation</th><th style="width:120px">Entitlements</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($meters as $meter)
                    <tr data-href="{{ route('billing.meters.show', $meter['id']) }}" tabindex="0" role="link" aria-label="Open meter {{ $meter['name'] }}">
                        <td><span style="display:block;font-weight:600">{{ $meter['name'] }}</span><span class="num mut" style="font-size:11px">{{ $meter['key'] }}</span></td>
                        <td class="mut">{{ $meter['unit'] }}</td>
                        <td><span class="cbx-pill cbx-pill--info">{{ $meter['aggregation'] }}</span></td>
                        <td class="num">{{ number_format($meter['entitlements']) }}</td>
                        <td>@if ($meter['archived'])<span class="cbx-pill cbx-pill--muted">archived</span>@else<span class="cbx-pill cbx-pill--success"><span class="dot"></span>live</span>@endif</td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No meters match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'gauge', 'size' => 18, 'sw' => 1.7])</div><h3>No meters yet.</h3><p>Create a meter to bill a usage dimension. <a href="{{ route('billing.meters.create') }}" class="cbx-link">New meter</a>.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $meters->links('partials.pagination') }}
</div>
@endsection
