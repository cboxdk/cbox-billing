@extends('layouts.app')
@section('title', 'Invoices')
@section('crumb', 'Invoices')

@php
    use App\Billing\BillingMetrics;
    $statusPill = ['paid' => 'success', 'open' => 'warning', 'draft' => 'muted'];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Invoices</h1>
            <p class="cbx-page-desc" style="font-size:13px">Issued this month · per-seller legal numbering</p>
        </div>
        <button class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) Create invoice</button>
    </header>

    <div class="filters">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input placeholder="Filter invoices…"><kbd class="k">F</kbd></div>
        <button class="fchip">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) Status</button>
        <button class="fchip">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) Seller</button>
        <span style="margin-left:auto" class="num mut">{{ count($invoices) }} results</span>
    </div>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th style="width:160px">Invoice</th><th>Customer</th><th style="width:100px">Date</th><th class="right" style="width:140px">Amount</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @foreach ($invoices as $inv)
                    <tr>
                        <td class="num">{{ $inv['number'] }}</td>
                        <td><span style="display:flex;align-items:center;gap:8px"><span class="avatar-sm" style="width:20px;height:20px;font-size:8px">{{ $inv['ini'] }}</span>{{ $inv['org'] }}</span></td>
                        <td class="num mut">{{ $inv['date'] }}</td>
                        <td class="right num">{{ BillingMetrics::formatMinor($inv['minor']) }}</td>
                        <td>
                            @php($v = $statusPill[$inv['status']] ?? 'muted')
                            <span class="cbx-pill cbx-pill--{{ $v }}">@if($inv['status'] !== 'draft')<span class="dot"></span>@endif{{ $inv['status'] }}</span>
                        </td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
