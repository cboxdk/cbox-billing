@extends('layouts.app')
@section('title', $detail['clock']['name'])
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Test clocks', 'href' => route('billing.test-mode.clocks')],
        ['label' => $detail['clock']['name']],
    ]" />
@endsection

@php
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    $clock = $detail['clock'];
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.test-mode.clocks')" label="Back to test clocks" />

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $clock['name'] }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">Virtual time <span class="num" style="font-weight:600">{{ $clock['now_at'] }}</span>. Advancing runs the due billing logic for the bound subscriptions.</p>
        </div>
    </header>

    @include('partials.flash')

    <div class="cbx-grid-2" style="align-items:start;gap:14px;margin-bottom:14px">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Advance the clock</h2></header>
            <form method="POST" action="{{ route('billing.test-mode.clocks.advance', $clock['id']) }}" style="padding:6px 20px 18px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"
                  data-confirm="Advance the clock? This runs due billing for the bound subscriptions and may raise invoices and drive dunning." data-confirm-title="Advance clock?" data-confirm-label="Advance" data-confirm-variant="primary">
                @csrf
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Advance to
                    <input type="datetime-local" name="target" required style="{{ $inputStyle }}">
                </label>
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'play', 'size' => 13, 'sw' => 1.8])Advance</button>
            </form>
        </section>

        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Charge outcome</h2></header>
            <form method="POST" action="{{ route('billing.test-mode.clocks.outcome', $clock['id']) }}" style="padding:6px 20px 18px;display:flex;gap:12px;align-items:flex-end">
                @csrf
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Fake gateway settles as
                    <select name="charge_outcome" style="{{ $inputStyle }}">
                        <option value="succeed" @selected($clock['charge_outcome'] === 'succeed')>Succeed — renewals settle</option>
                        <option value="decline" @selected($clock['charge_outcome'] === 'decline')>Decline — drives dunning</option>
                    </select>
                </label>
                <button type="submit" class="cbx-btn">Save</button>
            </form>
        </section>
    </div>

    <section class="cbx-panel" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Bound subscriptions</h2></header>
        <table class="tbl">
            <thead><tr><th>#</th><th>Organization</th><th>Plan</th><th>Status</th><th>Period end</th><th style="width:90px"></th></tr></thead>
            <tbody>
                @forelse ($detail['subscriptions'] as $sub)
                    <tr data-href="{{ route('billing.subscriptions.show', $sub['id']) }}" tabindex="0" role="link" aria-label="Open subscription {{ $sub['organization'] }}">
                        <td class="num mut">{{ $sub['id'] }}</td>
                        <td style="font-weight:500">{{ $sub['organization'] }}</td>
                        <td class="mut">{{ $sub['plan'] }}</td>
                        <td><span class="cbx-pill cbx-pill--muted">{{ $sub['status'] }}</span></td>
                        <td class="num mut">{{ $sub['trial_ends_at'] ? 'trial → '.$sub['trial_ends_at'] : $sub['period_end'] }}</td>
                        <td style="text-align:right">
                            <form method="POST" action="{{ route('billing.test-mode.clocks.unbind', [$clock['id'], $sub['id']]) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Unbind</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0"><div class="cbx-empty"><h3>No subscriptions bound.</h3><p>Bind a test subscription below to simulate its billing timeline.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($bindable->isNotEmpty())
            <form method="POST" action="{{ route('billing.test-mode.clocks.bind', $clock['id']) }}" style="padding:12px 20px;display:flex;gap:10px;align-items:flex-end;border-top:1px solid var(--border)">
                @csrf
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Bind a test subscription
                    <select name="subscription_id" style="{{ $inputStyle }};min-width:280px">
                        @foreach ($bindable as $sub)
                            <option value="{{ $sub->id }}">#{{ $sub->id }} — {{ $sub->organization?->name ?? $sub->organization_id }} ({{ $sub->plan?->name ?? '—' }})</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="cbx-btn">Bind</button>
            </form>
        @endif
    </section>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Invoices</h2></header>
        <table class="tbl">
            <thead><tr><th>Number</th><th>Period</th><th>Total</th><th>Status</th><th>Issued</th></tr></thead>
            <tbody>
                @forelse ($detail['invoices'] as $invoice)
                    <tr data-href="{{ route('billing.invoices.show', $invoice['id']) }}" tabindex="0" role="link" aria-label="Open invoice {{ $invoice['number'] }}">
                        <td class="num" style="font-weight:500"><a class="cbx-link" href="{{ route('billing.invoices.show', $invoice['id']) }}">{{ $invoice['number'] }}</a></td>
                        <td class="mut">{{ $invoice['period'] }}</td>
                        <td class="num">{{ $invoice['total'] }}</td>
                        <td>@if ($invoice['paid'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>paid</span>@else<span class="cbx-pill cbx-pill--warning"><span class="dot"></span>open</span>@endif</td>
                        <td class="num mut">{{ $invoice['issued'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><h3>No invoices yet.</h3><p>Advance the clock past a period boundary to raise one.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
