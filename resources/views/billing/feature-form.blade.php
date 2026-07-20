@extends('layouts.app')
@section('title', $feature !== null ? 'Edit feature' : 'New feature')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Features', 'href' => route('billing.features')],
        ['label' => $feature !== null ? 'Edit feature' : 'New feature'],
    ]" />
@endsection

@php
    $editing = $feature !== null;
    $action = $editing ? route('billing.features.update', $feature->id) : route('billing.features.store');
    $curKey = old('key', $editing ? $feature->key : '');
    $curName = old('name', $editing ? $feature->name : '');
    $curDesc = old('description', $editing ? $feature->description : '');
    $curType = old('type', $editing ? $feature->type->value : 'boolean');
    $curValueType = old('value_type', $editing ? $feature->value_type?->value : 'integer');
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit feature' : 'New feature' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">A boolean (on/off) or config (typed value/limit) product feature. Its key aligns with the on-prem license entitlement vocabulary so a hosted subscription and a license speak the same names.</p>
        </div>
        <a href="{{ $editing ? route('billing.features.show', $feature->id) : route('billing.features') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Key
                    <input type="text" name="key" value="{{ $curKey }}" required maxlength="120" placeholder="sso" pattern="[a-z0-9._-]+" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">Stable slug — lowercase, digits, dot, dash, underscore. Match a license capability (e.g. <span class="num">sso</span>, <span class="num">scim</span>) where one exists.</span>
                </label>
                <label style="{{ $labelStyle }}">Name
                    <input type="text" name="name" value="{{ $curName }}" required maxlength="160" placeholder="Single sign-on" style="{{ $inputStyle }}">
                </label>
            </div>

            <label style="{{ $labelStyle }}">Description <span class="mut" style="font-weight:400">(optional)</span>
                <input type="text" name="description" value="{{ $curDesc }}" maxlength="500" placeholder="What this feature unlocks" style="{{ $inputStyle }}">
            </label>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Type
                    <select name="type" id="ftype" required style="{{ $inputStyle }}" onchange="syncType()">
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected($curType === $type->value)>{{ $type->label() }} — {{ $type === \App\Billing\Features\Enums\FeatureType::Boolean ? 'on/off' : 'carries a typed value' }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="{{ $labelStyle }}" id="vtype-wrap">Value type <span class="mut" style="font-weight:400">(config only)</span>
                    <select name="value_type" id="vtype" style="{{ $inputStyle }}">
                        @foreach ($valueTypes as $valueType)
                            <option value="{{ $valueType->value }}" @selected($curValueType === $valueType->value)>{{ $valueType->label() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Create feature' }}</button>
                <a href="{{ $editing ? route('billing.features.show', $feature->id) : route('billing.features') }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>

<script>
    function syncType() {
        const isConfig = document.getElementById('ftype').value === 'config';
        document.getElementById('vtype-wrap').style.display = isConfig ? '' : 'none';
    }
    syncType();
</script>
@endsection
