@extends('layouts.app')
@section('title', 'Payment gateways')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings', ['tab' => 'gateways'])],
        ['label' => 'Payment gateways'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.settings', ['tab' => 'gateways']) }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to settings</a>

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Payment gateways</h1>
            <p class="cbx-page-desc" style="font-size:13px">Gateways are configured by environment variables, not the database. This page shows the real connection status and the exact keys each one needs.</p>
        </div>
    </header>

    <section class="cbx-panel" style="border-left:3px solid var(--border)">
        <div style="padding:14px 20px">
            <p class="cbx-page-desc" style="font-size:12px;margin:0">Set these in the deployment's environment and restart. The manual gateway settles out of band via a signed webhook; adapter gateways (Stripe, Mollie) come online when their credentials are present. Whichever gateway's credentials are set binds the live <span class="num">PaysInvoices</span> / <span class="num">WebhookVerifier</span> — there is no DB switch to flip.</p>
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
