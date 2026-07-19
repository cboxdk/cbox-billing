@extends('layouts.app')
@section('title', $entitlement !== null ? 'Edit entitlement' : 'New entitlement')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Catalog', 'href' => route('billing.catalog')],
        ['label' => $plan->name, 'href' => route('billing.plans.show', $plan->id)],
        ['label' => $entitlement !== null ? 'Edit entitlement' : 'New entitlement'],
    ]" />
@endsection

@php
    $editing = $entitlement !== null;
    $action = $editing
        ? route('billing.plans.entitlements.update', [$plan->id, $entitlement->id])
        : route('billing.plans.entitlements.store', $plan->id);
    $curMeter = (string) old('meter_id', $editing ? $entitlement->meter_id : '');
    $curEnabled = old('enabled', $editing ? ($entitlement->enabled ? '1' : '0') : '1');
    $curUnlimited = old('unlimited', $editing ? ($entitlement->unlimited ? '1' : '0') : '0');
    $curAllowance = old('allowance', $editing ? $entitlement->allowance : '');
    $curMultiplier = old('multiplier', $editing ? $entitlement->multiplier : '');
    $curOverage = old('overage', $editing ? $entitlement->overage->value : 'block');
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit entitlement' : 'New entitlement' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $plan->name }} · the included allowance and overage behaviour for one meter.</p>
        </div>
        <a href="{{ route('billing.plans.show', $plan->id) }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" id="ent-form" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <label style="{{ $labelStyle }}">Meter
                <select name="meter_id" required style="{{ $inputStyle }}">
                    <option value="">Select a meter…</option>
                    @foreach ($meters as $meter)
                        <option value="{{ $meter->id }}" @selected($curMeter === (string) $meter->id)>{{ $meter->name }} ({{ $meter->key }}) · {{ $meter->unit }}</option>
                    @endforeach
                </select>
                @if ($meters->isEmpty())<span class="mut" style="font-size:11px">No meters yet — <a href="{{ route('billing.meters.create') }}" style="color:var(--primary)">create one</a> first.</span>@endif
            </label>

            <div style="display:flex;gap:18px;flex-wrap:wrap">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" id="ent-enabled" value="1" @checked($curEnabled === '1' || $curEnabled === 1) onchange="syncEnt()"> Enabled
                </label>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500">
                    <input type="hidden" name="unlimited" value="0">
                    <input type="checkbox" name="unlimited" id="ent-unlimited" value="1" @checked($curUnlimited === '1' || $curUnlimited === 1) onchange="syncEnt()"> Unlimited
                </label>
            </div>

            <div class="cbx-grid-3" id="ent-costed" style="align-items:start">
                <label style="{{ $labelStyle }}">Allowance (units / period)
                    <input type="number" name="allowance" value="{{ $curAllowance }}" min="0" step="1" placeholder="100000" style="{{ $inputStyle }}">
                </label>
                <label style="{{ $labelStyle }}">Overage multiplier <span class="mut" style="font-weight:400">(cost / unit)</span>
                    <input type="number" name="multiplier" value="{{ $curMultiplier }}" min="0" step="any" placeholder="0.0005" style="{{ $inputStyle }}">
                </label>
                <label style="{{ $labelStyle }}">Overage behaviour
                    <select name="overage" style="{{ $inputStyle }}">
                        @foreach ($overages as $overage)
                            <option value="{{ $overage->value }}" @selected($curOverage === $overage->value)>{{ $overage->value }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="mut" id="ent-help" style="font-size:12px;margin:-6px 0 0"></p>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Add entitlement' }}</button>
                <a href="{{ route('billing.plans.show', $plan->id) }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>

<script>
    function syncEnt() {
        const enabled = document.getElementById('ent-enabled').checked;
        const unlimited = document.getElementById('ent-unlimited').checked;
        const costed = enabled && !unlimited;
        document.getElementById('ent-costed').style.display = costed ? '' : 'none';
        const help = document.getElementById('ent-help');
        help.textContent = !enabled
            ? 'Disabled: the feature is refused before any allowance or cost math.'
            : (unlimited ? 'Unlimited: entitled, never blocked, always zero cost.' : 'Metered: an isolated allowance, then the overage behaviour once it is spent.');
    }
    syncEnt();
</script>
@endsection
