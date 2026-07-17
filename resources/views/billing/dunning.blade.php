@extends('layouts.app')
@section('title', 'Dunning')
@section('crumb', 'Dunning')

@php
    use App\Billing\Support\MoneyFormatter;
    $retryPill = ['retrying' => 'warning', 'recovered' => 'success', 'exhausted' => 'destructive'];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Dunning</h1>
            <p class="cbx-page-desc" style="font-size:13px">Failed renewal charges under smart-retry · attempts, next retry &amp; outcome</p>
        </div>
    </header>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Customer</th><th style="width:150px">Invoice</th><th class="right" style="width:130px">Amount</th><th class="right" style="width:100px">Attempts</th><th style="width:120px">Next retry</th><th style="width:110px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($retries as $r)
                    <tr @if($r['subscription_id'])onclick="window.location='{{ route('billing.subscriptions.show', $r['subscription_id']) }}'"@else style="cursor:default"@endif>
                        <td><span style="display:flex;align-items:center;gap:10px"><span class="avatar-sm">{{ $r['ini'] }}</span>{{ $r['org'] }}</span></td>
                        <td class="num">{{ $r['invoice'] }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($r['invoice_minor'], $r['currency']) }}</td>
                        <td class="right num">{{ $r['attempts'] }} / {{ $r['max_attempts'] }}</td>
                        <td class="num mut">{{ $r['next_attempt_at'] }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $retryPill[$r['status']] ?? 'muted' }}"><span class="dot"></span>{{ $r['status'] }}</span></td>
                        <td class="rowchev">@if($r['subscription_id'])@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="mut" style="padding:24px;text-align:center">No failed charges in dunning.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
