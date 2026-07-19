@extends('layouts.app')
@section('title', 'Test clocks')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Test clocks'],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Test clocks</h1>
            <p class="cbx-page-desc" style="font-size:13px">A fast-forwardable virtual clock for the sandbox. Bind test subscriptions to a clock and advance its time to simulate renewals, trials and dunning in seconds — deterministically, with no real charges or emails.</p>
        </div>
    </header>

    @include('partials.flash')

    <section class="cbx-panel" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">New test clock</h2></header>
        <form method="POST" action="{{ route('billing.test-mode.clocks.store') }}" style="padding:6px 20px 18px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
            @csrf
            <label style="{{ $labelStyle }}">Name
                <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="Renewals scenario" style="{{ $inputStyle }};min-width:220px">
            </label>
            <label style="{{ $labelStyle }}">Start time <span class="mut" style="font-weight:400">(optional — defaults to now)</span>
                <input type="datetime-local" name="now_at" value="{{ old('now_at') }}" style="{{ $inputStyle }}">
            </label>
            <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.8])Create clock</button>
        </form>
    </section>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Clocks</h2></header>
        <table class="tbl">
            <thead><tr><th>Name</th><th>Virtual time</th><th>Charge outcome</th><th>Bound subs</th><th style="width:90px"></th></tr></thead>
            <tbody>
                @forelse ($clocks as $clock)
                    <tr data-href="{{ route('billing.test-mode.clocks.show', $clock['id']) }}" tabindex="0" role="link" aria-label="Open {{ $clock['name'] }}">
                        <td style="font-weight:500">{{ $clock['name'] }}</td>
                        <td class="num mut">{{ $clock['now_at'] }}</td>
                        <td>
                            @if ($clock['charge_outcome'] === 'decline')
                                <span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>decline</span>
                            @else
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>succeed</span>
                            @endif
                        </td>
                        <td class="num mut">{{ $clock['subscriptions'] }}</td>
                        <td style="text-align:right"><a href="{{ route('billing.test-mode.clocks.show', $clock['id']) }}" class="cbx-btn cbx-btn--sm">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'rotate', 'size' => 18, 'sw' => 1.7])</div><h3>No test clocks yet.</h3><p>Create one above to start simulating billing time.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
