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
            <p class="cbx-page-desc" style="font-size:13px">{{ count($customers) }} organizations · plan, standing and outstanding balance</p>
        </div>
    </header>

    <div class="filters">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input placeholder="Filter customers…"><kbd class="k">F</kbd></div>
        <span style="margin-left:auto" class="num mut">{{ count($customers) }} results</span>
    </div>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Organization</th><th style="width:110px">Plan</th><th style="width:110px">Status</th><th style="width:100px">Standing</th><th class="right" style="width:140px">Outstanding</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @foreach ($customers as $cust)
                    <tr onclick="window.location='{{ route('billing.customers.show', $cust['id']) }}'">
                        <td><span style="display:flex;align-items:center;gap:10px"><span class="avatar-sm">{{ $cust['ini'] }}</span><span><span style="display:block;font-weight:500">{{ $cust['org'] }}</span><span class="num mut" style="display:block;font-size:11px">{{ $cust['id'] }} · {{ $cust['country'] }}</span></span></span></td>
                        <td>{{ $cust['plan'] ?? '—' }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $statusPill[$cust['status']] ?? 'muted' }}">{{ $cust['status'] === 'none' ? 'no sub' : $cust['status'] }}</span></td>
                        <td><span class="cbx-pill cbx-pill--{{ $standingPill[$cust['standing']] ?? 'muted' }}">{{ $cust['standing'] }}</span></td>
                        <td class="right num">{{ $cust['outstanding_label'] }}</td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
