@extends('layouts.app')
@section('title', 'Invoices')
@section('crumb', 'Invoices')

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['paid' => 'success', 'open' => 'warning', 'draft' => 'muted', 'void' => 'muted'];
    $tabs = [
        ['key' => null, 'label' => 'All', 'count' => $counts['all']],
        ['key' => 'open', 'label' => 'Open', 'count' => $counts['open']],
        ['key' => 'paid', 'label' => 'Paid', 'count' => $counts['paid']],
        ['key' => 'draft', 'label' => 'Drafts', 'count' => $counts['draft']],
    ];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Invoices</h1>
            <p class="cbx-page-desc" style="font-size:13px">Per-seller legal numbering · tax-aware totals</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.credit-notes') }}">Credit notes</a>
            <a class="cbx-btn cbx-btn--primary cbx-btn--sm" href="{{ route('billing.invoices.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) New invoice</a>
        </div>
    </header>

    @include('partials.flash')

    <div class="cbx-tabs" style="min-height:40px;padding:4px 8px">
        <nav style="display:flex;flex:1;align-items:center;gap:2px">
            @foreach ($tabs as $tab)
                <a class="cbx-tab {{ $status === $tab['key'] ? 'cbx-tab--active' : '' }}"
                   href="{{ $tab['key'] ? route('billing.invoices', ['status' => $tab['key']]) : route('billing.invoices') }}"
                   style="padding:4px 9px">{{ $tab['label'] }}<span class="cbx-tab-count">{{ $tab['count'] }}</span></a>
            @endforeach
        </nav>
    </div>

    <form method="GET" action="{{ route('billing.invoices') }}" class="filters" role="search">
        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter invoices…" aria-label="Filter invoices"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ $status ? route('billing.invoices', ['status' => $status]) : route('billing.invoices') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $invoices->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th style="width:160px">Invoice</th><th>Customer</th><th style="width:100px">Date</th><th class="right" style="width:150px">Amount</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($invoices as $inv)
                    <tr data-href="{{ route('billing.invoices.show', $inv['id']) }}" tabindex="0" role="link" aria-label="Open invoice {{ $inv['number'] }}">
                        <td class="num">{{ $inv['number'] }}</td>
                        <td><span style="display:flex;align-items:center;gap:8px"><span class="avatar-sm" style="width:20px;height:20px;font-size:8px">{{ $inv['ini'] }}</span>{{ $inv['org'] }}</span></td>
                        <td class="num mut">{{ $inv['date'] }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($inv['minor'], $inv['currency']) }}</td>
                        <td>
                            @php($v = $statusPill[$inv['status']] ?? 'muted')
                            <span class="cbx-pill cbx-pill--{{ $v }}">@if($inv['status'] !== 'draft')<span class="dot"></span>@endif{{ $inv['status'] }}</span>
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No invoices match “{{ $search }}” in this view. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><h3>No invoices in this view.</h3><p>Issued invoices will appear here.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $invoices->links('partials.pagination') }}
</div>
@endsection
