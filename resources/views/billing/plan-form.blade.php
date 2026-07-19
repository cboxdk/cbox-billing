@extends('layouts.app')
@section('title', $plan !== null ? 'Edit plan' : 'New plan')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Catalog', 'href' => route('billing.catalog')],
        ['label' => 'Plans', 'href' => route('billing.pricing')],
        ['label' => $plan !== null ? 'Edit plan' : 'New plan'],
    ]" />
@endsection

@php
    $editing = $plan !== null;
    $action = $editing ? route('billing.plans.update', $plan->id) : route('billing.plans.store');
    $curProduct = (string) old('product_id', $editing ? $plan->product_id : '');
    $curKey = old('key', $editing ? $plan->key : '');
    $curName = old('name', $editing ? $plan->name : '');
    $curInterval = old('interval', $editing ? $plan->interval : 'month');
    $curActive = old('active', $editing ? ($plan->active ? '1' : '0') : '1');
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit plan' : 'New plan' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">Plan metadata only — a plan's money lives in its versioned per-currency prices, so editing here never reprices existing subscribers.</p>
        </div>
        <a href="{{ $editing ? route('billing.plans.show', $plan->id) : route('billing.pricing') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Product
                    <select name="product_id" required style="{{ $inputStyle }}">
                        <option value="">Select a product…</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected($curProduct === (string) $product->id)>{{ $product->name }} ({{ $product->key }})</option>
                        @endforeach
                    </select>
                </label>
                <label style="{{ $labelStyle }}">Interval
                    <select name="interval" required style="{{ $inputStyle }}">
                        @foreach ($intervals as $interval)
                            <option value="{{ $interval }}" @selected($curInterval === $interval)>per {{ $interval }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Key
                    <input type="text" name="key" value="{{ $curKey }}" required maxlength="120" placeholder="team" pattern="[a-z0-9._-]+" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">Stable handle — lowercase letters, digits, dot, dash, underscore.</span>
                </label>
                <label style="{{ $labelStyle }}">Name
                    <input type="text" name="name" value="{{ $curName }}" required maxlength="160" placeholder="Team" style="{{ $inputStyle }}">
                </label>
            </div>

            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" @checked($curActive === '1' || $curActive === 1)>
                Offered to new signups
                <span class="mut" style="font-weight:400">— unchecked makes it legacy (a valid transition source, closed to new signups).</span>
            </label>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Create plan' }}</button>
                <a href="{{ $editing ? route('billing.plans.show', $plan->id) : route('billing.pricing') }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>
@endsection
