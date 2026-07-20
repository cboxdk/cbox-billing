@extends('layouts.app')
@section('title', 'Pricing tables')
@section('crumb', 'Pricing tables')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Pricing tables</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $tables->total() }} tables · the embeddable, public pricing surfaces a marketing site drops in</p>
        </div>
        <a href="{{ route('billing.pricing-tables.create') }}" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New pricing table</a>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.pricing-tables') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter tables…" aria-label="Filter pricing tables"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.pricing-tables') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $tables->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Pricing table</th><th style="width:110px">Plans</th><th style="width:110px">Features</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($tables as $table)
                    <tr data-href="{{ route('billing.pricing-tables.show', $table->id) }}" tabindex="0" role="link" aria-label="Open pricing table {{ $table->name }}">
                        <td>
                            <span style="display:block;font-weight:600">{{ $table->name }}</span>
                            <span class="num mut" style="font-size:11px">/pricing/{{ $table->key }}</span>
                        </td>
                        <td class="num">{{ number_format($table->columns_count) }}</td>
                        <td class="num mut">{{ number_format($table->feature_rows_count) }}</td>
                        <td>
                            @if ($table->active)
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>live</span>
                            @else
                                <span class="cbx-pill cbx-pill--muted">offline</span>
                            @endif
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No pricing tables match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'grid', 'size' => 18, 'sw' => 1.7])</div><h3>No pricing tables yet.</h3><p>Author an embeddable pricing table your marketing site can drop in. <a href="{{ route('billing.pricing-tables.create') }}" style="color:var(--primary)">New pricing table</a>.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $tables->links('partials.pagination') }}
</div>
@endsection
