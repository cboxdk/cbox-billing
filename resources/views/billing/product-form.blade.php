@extends('layouts.app')
@section('title', $product !== null ? 'Edit product' : 'New product')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Products', 'href' => route('billing.products')],
        ['label' => $product !== null ? 'Edit product' : 'New product'],
    ]" />
@endsection

@php
    $editing = $product !== null;
    $action = $editing ? route('billing.products.update', $product->id) : route('billing.products.store');
    $curKey = old('key', $editing ? $product->key : '');
    $curName = old('name', $editing ? $product->name : '');
    $curDesc = old('description', $editing ? $product->description : '');
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit product' : 'New product' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">A product groups the plans you sell. It carries no money itself — plans do.</p>
        </div>
        <a href="{{ $editing ? route('billing.products.show', $product->id) : route('billing.products') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Key
                    <input type="text" name="key" value="{{ $curKey }}" required maxlength="120" placeholder="cbox-billing" pattern="[a-z0-9._-]+" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">Stable handle — lowercase letters, digits, dot, dash, underscore.</span>
                </label>
                <label style="{{ $labelStyle }}">Name
                    <input type="text" name="name" value="{{ $curName }}" required maxlength="160" placeholder="Cbox Billing" style="{{ $inputStyle }}">
                </label>
            </div>

            <label style="{{ $labelStyle }}">Description <span class="mut" style="font-weight:400">(optional)</span>
                <textarea name="description" maxlength="500" rows="3" placeholder="What this product is." style="{{ $inputStyle }};height:auto;padding:8px">{{ $curDesc }}</textarea>
            </label>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Create product' }}</button>
                <a href="{{ $editing ? route('billing.products.show', $product->id) : route('billing.products') }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>
@endsection
