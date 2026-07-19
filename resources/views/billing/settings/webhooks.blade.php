@extends('layouts.app')
@section('title', 'Webhooks')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings', ['tab' => 'webhooks']) ],
        ['label' => 'Webhooks'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.settings', ['tab' => 'webhooks']) }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to settings</a>

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Webhooks</h1>
            <p class="cbx-page-desc" style="font-size:13px">This app runs two inbound webhook receivers. Both signing secrets are environment variables (deny-by-default — no secret refuses every payload), so this page shows their status and how to rotate them.</p>
        </div>
    </header>

    @foreach ($receivers as $receiver)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
                <h2 class="cbx-panel-title" style="font-size:14px">{{ $receiver['name'] }} receiver</h2>
                @if ($receiver['configured'])
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>configured</span>
                @else
                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>deny-by-default (no secret)</span>
                @endif
            </header>
            <dl style="margin:0;padding:6px 20px 12px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Endpoint</dt><dd class="num">{{ $receiver['endpoint'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Signature header</dt><dd class="num">{{ $receiver['signature_header'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Signing secret</dt><dd class="num">{{ $receiver['env_key'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0;align-items:flex-start"><dt>Rotation</dt><dd style="font-size:12px">Generate a new random secret, set <span class="num">{{ $receiver['env_key'] }}</span> in the environment, redeploy, then update the sender to sign with the new value. The old secret stops verifying the moment the new one is live — rotate the sender in the same window to avoid a gap.</dd></div>
                <div class="cbx-kv" style="padding:9px 0;align-items:flex-start"><dt>About</dt><dd style="font-size:12px" class="mut">{{ $receiver['description'] }}</dd></div>
            </dl>
        </section>
    @endforeach
</div>
@endsection
