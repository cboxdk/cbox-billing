@extends('layouts.app')
@section('title', 'Payment gateways')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings').'#gateways'],
        ['label' => 'Payment gateways'],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    $isProduction = $environment['is_production'];
    $keyMode = $environment['gateway_key_mode'];
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.settings').'#gateways'" label="Back to settings" />

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Payment gateways</h1>
            <p class="cbx-page-desc" style="font-size:13px">Enter per-environment Stripe credentials for the active plane (stored encrypted). A plane with no credentials falls back to the global environment variables in production, or the fake test gateway in a sandbox.</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- Per-environment Stripe credentials for the active plane. --}}
    <section class="cbx-panel" id="environment-keys">
        <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
            <h2 class="cbx-panel-title" style="font-size:14px">Stripe keys · {{ $environment['name'] }} <span class="num mut" style="font-size:11px;margin-left:6px">{{ $environment['key'] }}</span></h2>
            @if ($stripe['configured'])
                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>keys set</span>
            @else
                <span class="cbx-pill cbx-pill--muted">not set — using fallback</span>
            @endif
        </header>
        <div style="padding:0 20px">
            <p class="cbx-page-desc" style="font-size:12px;margin:0 0 4px">
                @if ($isProduction)
                    This is <strong>production</strong> — it requires <strong>live</strong> Stripe keys (<span class="num">sk_live_…</span>). A test key is refused so production always charges the real account.
                @else
                    This is a <strong>sandbox</strong> — enter <strong>test keys only</strong> (<span class="num">sk_test_…</span>). A live key is refused so a real card can never be charged here.
                @endif
            </p>
        </div>
        <form method="POST" action="{{ route('billing.settings.gateways.store') }}" style="padding:12px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            <label style="{{ $labelStyle }}">Secret key
                <input type="password" name="secret" required maxlength="255" placeholder="{{ $isProduction ? 'sk_live_…' : 'sk_test_…' }}" autocomplete="off" style="{{ $inputStyle }}">
                <span class="mut" style="font-size:11px">Stored encrypted at rest; never displayed again. Enter it to (re)set the plane's credentials.</span>
            </label>
            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Publishable key <span class="mut" style="font-weight:400">(optional)</span>
                    <input type="text" name="publishable" value="{{ $stripe['publishable'] }}" maxlength="255" placeholder="{{ $isProduction ? 'pk_live_…' : 'pk_test_…' }}" style="{{ $inputStyle }}">
                </label>
                <label style="{{ $labelStyle }}">Webhook signing secret <span class="mut" style="font-weight:400">(optional)</span>
                    <input type="password" name="webhook_secret" maxlength="255" placeholder="{{ $stripe['has_webhook_secret'] ? '•••••• (set)' : 'whsec_…' }}" autocomplete="off" style="{{ $inputStyle }}">
                </label>
            </div>
            <div>
                <button type="submit" class="cbx-btn cbx-btn--primary">Save {{ $keyMode }} keys</button>
            </div>
        </form>
    </section>

    <section class="cbx-panel" style="border-left:3px solid var(--border)">
        <div style="padding:14px 20px">
            <p class="cbx-page-desc" style="font-size:12px;margin:0">Below is the legacy GLOBAL env-var status (the BC fallback). The manual gateway settles out of band via a signed webhook; adapter gateways (Stripe, Mollie) come online when their credentials are present. A plane's own keys above take precedence over these.</p>
        </div>
    </section>

    @foreach ($gateways as $gateway)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
                <h2 class="cbx-panel-title" style="font-size:14px">{{ $gateway['name'] }}<span class="num mut" style="font-size:11px;margin-left:8px">{{ $gateway['mode'] }}</span></h2>
                @if ($gateway['connected'])
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>connected</span>
                @else
                    <span class="cbx-pill cbx-pill--muted">not configured</span>
                @endif
            </header>
            <dl style="margin:0;padding:6px 20px 12px">
                <div class="cbx-kv" style="padding:9px 0;align-items:flex-start">
                    <dt>Environment keys</dt>
                    <dd>
                        @forelse ($gateway['env_keys'] as $key)
                            <div class="num" style="font-size:12px">{{ $key }}</div>
                        @empty
                            <span class="mut">—</span>
                        @endforelse
                    </dd>
                </div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Status</dt><dd>{{ $gateway['connected'] ? 'Credentials present — this gateway is available.' : 'No credentials — set the keys above to enable it.' }}</dd></div>
            </dl>
        </section>
    @endforeach
</div>
@endsection
