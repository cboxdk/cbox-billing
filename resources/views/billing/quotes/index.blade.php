@extends('layouts.app')
@section('title', 'Quotes')
@section('crumb', 'Quotes')

@php
    use App\Billing\Cpq\Enums\QuoteStatus;
    use App\Billing\Support\MoneyFormatter;
    $tabs = [
        ['key' => 'all', 'label' => 'All', 'count' => $counts['all'] ?? 0],
        ['key' => 'draft', 'label' => 'Drafts', 'count' => $counts['draft'] ?? 0],
        ['key' => 'pending_approval', 'label' => 'Pending approval', 'count' => $counts['pending_approval'] ?? 0],
        ['key' => 'approved', 'label' => 'Approved', 'count' => $counts['approved'] ?? 0],
        ['key' => 'sent', 'label' => 'Sent', 'count' => $counts['sent'] ?? 0],
        ['key' => 'accepted', 'label' => 'Accepted', 'count' => $counts['accepted'] ?? 0],
        ['key' => 'declined', 'label' => 'Declined', 'count' => $counts['declined'] ?? 0],
        ['key' => 'expired', 'label' => 'Expired', 'count' => $counts['expired'] ?? 0],
    ];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Quotes</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $counts['all'] ?? 0 }} quotes · {{ $counts['pending_approval'] ?? 0 }} pending approval · {{ $counts['sent'] ?? 0 }} out with customers · {{ $counts['accepted'] ?? 0 }} accepted</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a class="cbx-btn cbx-btn--sm" href="{{ route('billing.quotes.approvals') }}">@include('partials.icon', ['name' => 'shield', 'size' => 14, 'sw' => 1.7]) Approval queue @if(($counts['pending_approval'] ?? 0) > 0)<span class="cbx-tab-count">{{ $counts['pending_approval'] }}</span>@endif</a>
            <a class="cbx-btn cbx-btn--primary cbx-btn--sm" href="{{ route('billing.quotes.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) New quote</a>
        </div>
    </header>

    @include('partials.flash')

    <div class="cbx-tabs" style="min-height:40px;padding:4px 8px">
        <nav style="display:flex;flex:1;align-items:center;gap:2px;flex-wrap:wrap">
            @foreach ($tabs as $t)
                <a class="cbx-tab {{ $tab === $t['key'] ? 'cbx-tab--active' : '' }}"
                   href="{{ $t['key'] === 'all' ? route('billing.quotes') : route('billing.quotes', ['status' => $t['key']]) }}"
                   style="padding:4px 9px">{{ $t['label'] }}<span class="cbx-tab-count">{{ $t['count'] }}</span></a>
            @endforeach
        </nav>
    </div>

    <form method="GET" action="{{ route('billing.quotes') }}" class="filters" role="search">
        @if ($tab !== 'all')<input type="hidden" name="status" value="{{ $tab }}">@endif
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter by number, prospect or customer…" aria-label="Filter quotes"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ $tab === 'all' ? route('billing.quotes') : route('billing.quotes', ['status' => $tab]) }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $quotes->total() }}{{ $search ? ' matching' : '' }} of {{ $counts['all'] ?? 0 }}</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Quote</th><th>Customer</th><th style="width:120px">Currency</th><th style="width:150px">Status</th><th style="width:150px">Updated</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($quotes as $quote)
                    <tr data-href="{{ route('billing.quotes.show', $quote->id) }}" tabindex="0" role="link" aria-label="Open quote {{ $quote->number }}">
                        <td><span style="display:block;font-weight:600" class="num">{{ $quote->number }}</span></td>
                        <td>{{ $quote->customerName() }}</td>
                        <td class="num mut">{{ $quote->currency }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $quote->status->tone() === 'neutral' ? 'muted' : $quote->status->tone() }}">{{ $quote->status->label() }}</span></td>
                        <td class="num mut">{{ $quote->updated_at?->diffForHumans() }}</td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No quotes match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'receipt', 'size' => 18, 'sw' => 1.7])</div><h3>No quotes yet.</h3><p>Author a sales quote — line items, contract terms, and a branded order form your customer accepts. <a href="{{ route('billing.quotes.create') }}" style="color:var(--primary)">New quote</a>.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $quotes->links('partials.pagination') }}
</div>
@endsection
