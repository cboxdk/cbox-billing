@extends('layouts.app')
@section('title', $grant !== null ? 'Edit feature grant' : 'New feature grant')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Catalog', 'href' => route('billing.catalog')],
        ['label' => $plan->name, 'href' => route('billing.plans.show', $plan->id)],
        ['label' => $grant !== null ? 'Edit feature grant' : 'New feature grant'],
    ]" />
@endsection

@php
    $editing = $grant !== null;
    $action = $editing
        ? route('billing.plans.features.update', [$plan->id, $grant->id])
        : route('billing.plans.features.store', $plan->id);
    $curFeature = (string) old('feature_id', $editing ? $grant->feature_id : '');
    $curEnabled = old('enabled', $editing ? ($grant->enabled ? '1' : '0') : '1');
    $curValue = old('value', $editing ? $grant->value : '');
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    $configIds = $features->where('type', \App\Billing\Features\Enums\FeatureType::Config)->pluck('id')->map(fn ($id) => (string) $id)->all();
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit feature grant' : 'New feature grant' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $plan->name }} · which boolean/config feature this plan grants. A config feature also carries a typed value (e.g. <span class="num">max_projects = 10</span>).</p>
        </div>
        <a href="{{ route('billing.plans.show', $plan->id) }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <label style="{{ $labelStyle }}">Feature
                <select name="feature_id" id="pf-feature" required style="{{ $inputStyle }}" onchange="syncFeature()">
                    <option value="">Select a feature…</option>
                    @foreach ($features as $feature)
                        <option value="{{ $feature->id }}" @selected($curFeature === (string) $feature->id)>{{ $feature->name }} ({{ $feature->key }}) · {{ $feature->type->value }}</option>
                    @endforeach
                </select>
                @if ($features->isEmpty())<span class="mut" style="font-size:11px">No features yet — <a href="{{ route('billing.features.create') }}" class="cbx-link">create one</a> first.</span>@endif
            </label>

            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked($curEnabled === '1' || $curEnabled === 1)> Granted
            </label>

            <label style="{{ $labelStyle }}" id="pf-value-wrap">Config value <span class="mut" style="font-weight:400">(config features only)</span>
                <input type="text" name="value" value="{{ $curValue }}" maxlength="255" placeholder="10" style="{{ $inputStyle }}">
                <span class="mut" style="font-size:11px">Typed on resolution by the feature's value type. Leave blank for a boolean feature.</span>
            </label>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Add grant' }}</button>
                <a href="{{ route('billing.plans.show', $plan->id) }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>

<script>
    const CONFIG_IDS = @json($configIds);
    function syncFeature() {
        const id = document.getElementById('pf-feature').value;
        document.getElementById('pf-value-wrap').style.display = CONFIG_IDS.includes(id) ? '' : 'none';
    }
    syncFeature();
</script>
@endsection
