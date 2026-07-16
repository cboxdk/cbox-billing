@extends('layouts.app')
@section('title', 'License distribution')
@section('crumb', 'Licenses')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Distribution</h1>
            <p class="cbx-page-desc" style="font-size:13px">The public key and signed revocation list for air-gapped hand-off to self-hosted deployments</p>
        </div>
    </header>

    {{-- Public key --}}
    <section class="cbx-panel" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Issuer public key</h2></header>
        <div style="padding:6px 20px 16px">
            @if ($settings['public_key_configured'])
                <p class="cbx-page-desc" style="font-size:12px;margin:0 0 6px">Safe to share. Bundle it in the self-hosted deployment as <span class="num">CBOX_LICENSE_PUBLIC_KEY</span> so it can verify licenses offline:</p>
                <textarea readonly rows="2" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:11px;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);resize:vertical">{{ $settings['public_key'] }}</textarea>
            @else
                <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>not configured</span>
                <p class="cbx-page-desc" style="font-size:12px;margin:8px 0 0">Set <span class="num">CBOX_LICENSE_PUBLIC_KEY</span>. Generate a keypair with <span class="num">php artisan billing:license-keygen</span>.</p>
            @endif
        </div>
    </section>

    {{-- Signing key status --}}
    <section class="cbx-panel" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Signing key</h2></header>
        <dl style="margin:0;padding:2px 20px 6px">
            <div class="cbx-kv" style="padding:9px 0"><dt>Private signing key</dt><dd>@if($settings['signing_key_configured'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>configured (never displayed)</span>@else<span class="cbx-pill cbx-pill--warning"><span class="dot"></span>not configured — licensing inert</span>@endif</dd></div>
            <div class="cbx-kv" style="padding:9px 0"><dt>Revoked licenses</dt><dd class="num">{{ $settings['revoked_count'] }}</dd></div>
        </dl>
    </section>

    {{-- Signed revocation list --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Signed revocation list</h2></header>
        <div style="padding:6px 20px 16px">
            @if ($settings['signing_key_configured'])
                <p class="cbx-page-desc" style="font-size:12px;margin:0 0 6px">The current signed list of revoked license ids ({{ $settings['revoked_count'] }}). Distribute it to deployments (or expose the activation endpoint) so a revoked license is refused offline once pulled:</p>
                <textarea readonly rows="3" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:11px;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);resize:vertical">{{ $settings['revocation_list'] }}</textarea>
            @else
                <p class="cbx-page-desc" style="font-size:12px;margin:0">Available once a signing key is configured.</p>
            @endif
        </div>
    </section>
</div>
@endsection
