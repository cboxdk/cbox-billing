@extends('layouts.app')
@section('title', 'Experiments')
@section('crumb', 'Experiments')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Pricing experiments</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $experiments->total() }} experiments · controlled A/B tests on your public pricing tables</p>
        </div>
        <a href="{{ route('billing.experiments.create') }}" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])New experiment</a>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.experiments') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter experiments…" aria-label="Filter experiments"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.experiments') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $experiments->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Experiment</th><th style="width:130px">Runs on</th><th style="width:90px">Variants</th><th style="width:150px">Impr. · conv.</th><th style="width:110px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($experiments as $experiment)
                    <tr data-href="{{ route('billing.experiments.show', $experiment->id) }}" tabindex="0" role="link" aria-label="Open experiment {{ $experiment->name }}">
                        <td>
                            <span style="display:block;font-weight:600">{{ $experiment->name }}</span>
                            <span class="num mut" style="font-size:11px">{{ $experiment->key }} · {{ $experiment->primary_metric->label() }}</span>
                        </td>
                        <td>
                            @if ($experiment->pricingTable)
                                <span class="num mut" style="font-size:11px">/pricing/{{ $experiment->pricingTable->key }}</span>
                            @else
                                <span class="mut">—</span>
                            @endif
                        </td>
                        <td class="num">{{ number_format($experiment->variants_count) }}</td>
                        <td class="num mut">{{ number_format($experiment->impressions_count) }} · {{ number_format($experiment->conversions_count) }}</td>
                        <td>
                            <span class="cbx-pill cbx-pill--{{ $experiment->status->tone() }}">@if($experiment->status->isServing())<span class="dot"></span>@endif{{ $experiment->status->label() }}</span>
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No experiments match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'flask', 'size' => 18, 'sw' => 1.7])</div><h3>No experiments yet.</h3><p>Run a controlled A/B test on one of your <a href="{{ route('billing.pricing-tables') }}" style="color:var(--primary)">pricing tables</a> and measure conversion by variant. <a href="{{ route('billing.experiments.create') }}" style="color:var(--primary)">New experiment</a>.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $experiments->links('partials.pagination') }}
</div>
@endsection
