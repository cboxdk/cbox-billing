@extends('layouts.app')
@section('title', 'Features')
@section('crumb', 'Features')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Features</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $features->total() }} boolean / config product features · the gating dimension plans grant and the API resolves</p>
        </div>
        <a href="{{ route('billing.features.create') }}" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New feature</a>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.features') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter features…" aria-label="Filter features"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.features') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $features->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Feature</th><th style="width:110px">Type</th><th style="width:110px">Value type</th><th style="width:110px">Plan grants</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($features as $feature)
                    <tr data-href="{{ route('billing.features.show', $feature['id']) }}" tabindex="0" role="link" aria-label="Open feature {{ $feature['name'] }}">
                        <td><span style="display:block;font-weight:600">{{ $feature['name'] }}</span><span class="num mut" style="font-size:11px">{{ $feature['key'] }}</span></td>
                        <td><span class="cbx-pill cbx-pill--{{ $feature['type'] === 'config' ? 'info' : 'muted' }}">{{ $feature['type'] }}</span></td>
                        <td class="mut">{{ $feature['value_type'] ?? '—' }}</td>
                        <td class="num">{{ number_format($feature['grants']) }}</td>
                        <td>@if ($feature['archived'])<span class="cbx-pill cbx-pill--muted">archived</span>@else<span class="cbx-pill cbx-pill--success"><span class="dot"></span>live</span>@endif</td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No features match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>No features yet.</h3><p>Create a boolean or config feature to gate a capability. <a href="{{ route('billing.features.create') }}" style="color:var(--primary)">New feature</a>.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $features->links('partials.pagination') }}
</div>
@endsection
