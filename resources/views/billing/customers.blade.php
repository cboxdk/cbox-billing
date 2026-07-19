@extends('layouts.app')
@section('title', 'Customers')
@section('crumb', 'Customers')

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning', 'canceled' => 'muted', 'none' => 'muted'];
    $standingPill = ['good' => 'success', 'disputed' => 'warning', 'suspended' => 'destructive'];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Customers</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $customers->total() }} customers · plan, standing and outstanding balance</p>
        </div>
    </header>

    <form method="GET" action="{{ route('billing.customers') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter customers…" aria-label="Filter customers"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.customers') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $customers->total() }}{{ $search ? ' matching' : '' }} results</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Customer</th><th style="width:110px">Plan</th><th style="width:110px">Status</th><th style="width:100px">Standing</th><th class="right" style="width:140px">Outstanding</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($customers as $cust)
                    <tr data-href="{{ route('billing.customers.show', $cust['id']) }}" tabindex="0" role="link" aria-label="Open customer {{ $cust['org'] }}">
                        <td><span style="display:flex;align-items:center;gap:10px"><span class="avatar-sm">{{ $cust['ini'] }}</span><span><span style="display:block;font-weight:500">{{ $cust['org'] }}</span><span class="num mut" style="display:block;font-size:11px">{{ $cust['id'] }} · {{ $cust['country'] }}</span></span></span></td>
                        <td>{{ $cust['plan'] ?? '—' }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $statusPill[$cust['status']] ?? 'muted' }}">{{ $cust['status'] === 'none' ? 'no sub' : $cust['status'] }}</span></td>
                        <td><span class="cbx-pill cbx-pill--{{ $standingPill[$cust['standing']] ?? 'muted' }}">{{ $cust['standing'] }}</span></td>
                        <td class="right num">{{ $cust['outstanding_label'] }}</td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No customers match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'building', 'size' => 18, 'sw' => 1.7])</div><h3>No customers yet.</h3><p>Customers you bill will appear here.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $customers->links('partials.pagination') }}
</div>
@endsection
