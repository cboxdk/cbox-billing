@extends('layouts.app')
@section('title', 'Credit notes')
@section('crumb', 'Credit notes')

@php
    use App\Billing\Support\MoneyFormatter;
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Credit notes</h1>
            <p class="cbx-page-desc" style="font-size:13px">The legal record of refunds &amp; adjustments · issued off each seller's own sequence</p>
        </div>
        <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.invoices') }}">Back to invoices</a>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.credit-notes') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter credit notes…" aria-label="Filter credit notes"><kbd class="k">F</kbd></div>
        @if ($search)<a href="{{ route('billing.credit-notes') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>@endif
        <span style="margin-left:auto" class="num mut">{{ $creditNotes->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th style="width:180px">Credit note</th><th>Customer</th><th style="width:160px">Invoice</th><th>Reason</th><th style="width:100px">Issued</th><th class="right" style="width:150px">Credited</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($creditNotes as $note)
                    <tr data-href="{{ route('billing.credit-notes.show', $note['id']) }}" tabindex="0" role="link" aria-label="Open credit note {{ $note['number'] }}">
                        <td class="num">{{ $note['number'] }}</td>
                        <td><span style="display:flex;align-items:center;gap:8px"><span class="avatar-sm" style="width:20px;height:20px;font-size:8px">{{ $note['ini'] }}</span>{{ $note['org'] }}</span></td>
                        <td class="num mut">{{ $note['invoice_number'] }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $note['reason'])) }}</td>
                        <td class="num mut">{{ $note['date'] }}</td>
                        <td class="right num">−{{ MoneyFormatter::minor($note['minor'], $note['currency']) }}</td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No credit notes match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><h3>No credit notes yet.</h3><p>Credit notes are issued when you refund or adjust an invoice.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $creditNotes->links('partials.pagination') }}
</div>
@endsection
