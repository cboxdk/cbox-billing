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
    </header>

    <div class="cbx-tabs" style="min-height:40px;padding:4px 8px">
        <nav style="display:flex;flex:1;align-items:center;gap:2px">
            @foreach ($tabs as $tab)
                <a class="cbx-tab {{ $status === $tab['key'] ? 'cbx-tab--active' : '' }}"
                   href="{{ $tab['key'] ? route('billing.invoices', ['status' => $tab['key']]) : route('billing.invoices') }}"
                   style="padding:4px 9px">{{ $tab['label'] }}<span class="cbx-tab-count">{{ $tab['count'] }}</span></a>
            @endforeach
        </nav>
    </div>

    <div class="filters">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input placeholder="Filter invoices…"><kbd class="k">F</kbd></div>
        <span style="margin-left:auto" class="num mut">{{ count($invoices) }} results</span>
    </div>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th style="width:160px">Invoice</th><th>Customer</th><th style="width:100px">Date</th><th class="right" style="width:150px">Amount</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($invoices as $inv)
                    <tr onclick="window.location='{{ route('billing.invoices.show', $inv['id']) }}'">
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
                    <tr><td colspan="6" class="mut" style="padding:24px;text-align:center">No invoices in this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
