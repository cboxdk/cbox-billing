@extends('layouts.app')
@section('title', $grant !== null ? 'Edit credit grant' : 'New credit grant')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Catalog', 'href' => route('billing.catalog')],
        ['label' => $plan->name, 'href' => route('billing.plans.show', $plan->id)],
        ['label' => $grant !== null ? 'Edit credit grant' : 'New credit grant'],
    ]" />
@endsection

@php
    $editing = $grant !== null;
    $action = $editing
        ? route('billing.plans.credit-grants.update', [$plan->id, $grant->id])
        : route('billing.plans.credit-grants.store', $plan->id);
    $curPool = old('pool', $editing ? $grant->pool : 'included');
    $curKind = old('kind', $editing ? $grant->kind->value : 'base');
    $curCadence = old('cadence', $editing ? $grant->cadence->value : 'monthly');
    $curAmount = old('amount', $editing ? $grant->amount : '');
    $curMode = old('amount_mode', $editing ? $grant->amount_mode->value : 'fixed');
    $curRollover = old('rollover_seconds', $editing ? $grant->rollover_seconds : '');
    $curDenom = old('denomination', $editing ? $grant->denomination : 'credit');
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit credit grant' : 'New credit grant' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $plan->name }} · the recurring or one-time credits the wallet vests each cadence boundary.</p>
        </div>
        <a href="{{ route('billing.plans.show', $plan->id) }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <div class="cbx-grid-3" style="align-items:start">
                <label style="{{ $labelStyle }}">Pool
                    <select name="pool" required style="{{ $inputStyle }}">
                        @foreach ($pools as $pool)
                            <option value="{{ $pool }}" @selected($curPool === $pool)>{{ $pool }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="{{ $labelStyle }}">Kind
                    <select name="kind" required style="{{ $inputStyle }}">
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind->value }}" @selected($curKind === $kind->value)>{{ $kind->value }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="{{ $labelStyle }}">Cadence
                    <select name="cadence" required style="{{ $inputStyle }}">
                        @foreach ($cadences as $cadence)
                            <option value="{{ $cadence->value }}" @selected($curCadence === $cadence->value)>{{ $cadence->value }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="cbx-grid-3" style="align-items:start">
                <label style="{{ $labelStyle }}">Amount
                    <input type="number" name="amount" value="{{ $curAmount }}" required min="0" step="1" placeholder="250000" style="{{ $inputStyle }}">
                </label>
                <label style="{{ $labelStyle }}">Amount mode
                    <select name="amount_mode" required style="{{ $inputStyle }}">
                        @foreach ($amountModes as $mode)
                            <option value="{{ $mode->value }}" @selected($curMode === $mode->value)>{{ $mode->value }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="{{ $labelStyle }}">Denomination
                    <input type="text" name="denomination" value="{{ $curDenom }}" required maxlength="60" placeholder="credit" style="{{ $inputStyle }}">
                </label>
            </div>

            <label style="{{ $labelStyle }};max-width:260px">Rollover window (seconds) <span class="mut" style="font-weight:400">(optional)</span>
                <input type="number" name="rollover_seconds" value="{{ $curRollover }}" min="0" step="1" placeholder="0" style="{{ $inputStyle }}">
                <span class="mut" style="font-size:11px">Unused balance survives this long into the next period. Ignored for a one-time (once) grant.</span>
            </label>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Add credit grant' }}</button>
                <a href="{{ route('billing.plans.show', $plan->id) }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>
@endsection
