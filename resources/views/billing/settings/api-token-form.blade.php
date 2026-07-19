@extends('layouts.app')
@section('title', 'New API token')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings', ['tab' => 'tokens'])],
        ['label' => 'New API token'],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.settings').'#tokens'" label="Back to settings" />

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">New API token</h1>
            <p class="cbx-page-desc" style="font-size:13px">A bearer token for the management / enforcement API. The plaintext is shown once, then only its hash is stored.</p>
        </div>
        <a href="{{ route('billing.settings', ['tab' => 'tokens']) }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ route('billing.settings.tokens.store') }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf

            <label style="{{ $labelStyle }}">Label
                <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="cbox-assistant prod" style="{{ $inputStyle }}">
                <span class="mut" style="font-size:11px">A recognizable name for this token — how you'll identify it in the list.</span>
            </label>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Organization scope <span class="mut" style="font-weight:400">(optional)</span>
                    <select name="organization_id" style="{{ $inputStyle }}">
                        <option value="">Operator — acts for every organization</option>
                        @foreach ($organizations as $org)
                            <option value="{{ $org->id }}" @selected(old('organization_id') === $org->id)>{{ $org->name }} ({{ $org->id }})</option>
                        @endforeach
                    </select>
                    <span class="mut" style="font-size:11px">Leave as operator for a platform token, or bind it to one org.</span>
                </label>
                <label style="{{ $labelStyle }}">Product scope <span class="mut" style="font-weight:400">(optional)</span>
                    <select name="product_id" style="{{ $inputStyle }}">
                        <option value="">All products</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->name }} ({{ $product->key }})</option>
                        @endforeach
                    </select>
                    <span class="mut" style="font-size:11px">Bind the token so it only sees/sells one product's catalog.</span>
                </label>
            </div>

            <label style="{{ $labelStyle }}">Mode
                <select name="mode" style="{{ $inputStyle }}">
                    <option value="live" @selected(old('mode', 'live') === 'live')>Live — operates on real data</option>
                    <option value="test" @selected(old('mode') === 'test')>Test — sandbox dataset only (fake gateway, no email)</option>
                </select>
                <span class="mut" style="font-size:11px">A test token sees and writes only <code>livemode=false</code> rows and routes charges through the fake gateway. Its plaintext carries a <code>cbt_</code> prefix.</span>
            </label>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'key', 'size' => 14, 'sw' => 1.7])Mint token</button>
                <a href="{{ route('billing.settings', ['tab' => 'tokens']) }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>
@endsection
