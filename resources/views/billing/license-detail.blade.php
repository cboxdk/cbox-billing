@extends('layouts.app')
@section('title', 'License')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Licenses', 'href' => route('billing.licenses')],
        ['label' => $license['id']],
    ]" />
@endsection

@php
    $statusPill = ['active' => 'success', 'expiring' => 'warning', 'expired' => 'muted', 'revoked' => 'destructive'];
    $l = $license;
@endphp

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.licenses') }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to licenses</a>

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title num" style="font-size:19px">{{ $l['id'] }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $l['plan'] }} · deployment <span class="num">{{ $l['deployment_id'] }}</span></p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="cbx-pill cbx-pill--{{ $statusPill[$l['status']] ?? 'muted' }}">{{ $l['status'] }}</span>
            @if ($l['status'] !== 'revoked')
                <form method="POST" action="{{ route('billing.licenses.renew', ['id' => $l['id']]) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Renew</button></form>
                <form method="POST" action="{{ route('billing.licenses.revoke', ['id' => $l['id']]) }}" style="margin:0"
                      data-confirm="Revoke license {{ $l['id'] }}? It will be refused once the new revocation list is pulled. This cannot be undone."
                      data-confirm-title="Revoke license?" data-confirm-label="Revoke license" data-confirm-variant="destructive">
                    @csrf<button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Revoke</button>
                </form>
            @endif
        </div>
    </header>

    @if ($l['revoked'])
        <section class="cbx-panel" style="border-left:3px solid var(--destructive)">
            <div style="padding:12px 20px"><strong>Revoked {{ $l['revoked_at'] }}.</strong> <span class="mut">{{ $l['revoked_reason'] ?? 'No reason recorded.' }}</span> It is refused offline once the deployment pulls the new revocation list.</div>
        </section>
    @endif

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Binding</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Customer</dt><dd><a href="{{ route('billing.customers.show', $l['customer_id']) }}">{{ $l['customer_name'] ?? $l['customer_id'] }}</a></dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Deployment</dt><dd class="num">{{ $l['deployment_id'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Licensed domain</dt><dd class="num">{{ $l['licensed_domain'] ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Plan profile</dt><dd class="num">{{ $l['plan'] }}</dd></div>
            </dl>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Window</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Issued</dt><dd class="num">{{ $l['issued_at']->format('Y-m-d') }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Valid from</dt><dd class="num">{{ $l['not_before']->format('Y-m-d') }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Expires</dt><dd class="num">{{ $l['expires_at']->format('Y-m-d') }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Status</dt><dd><span class="cbx-pill cbx-pill--{{ $statusPill[$l['status']] ?? 'muted' }}">{{ $l['status'] }}</span></dd></div>
            </dl>
        </section>
    </div>

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Entitlements</h2></header>
            <div style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:6px">
                @forelse ($l['entitlements'] as $entitlement)
                    <span class="cbx-pill cbx-pill--info">{{ $entitlement }}</span>
                @empty
                    <span class="mut">None.</span>
                @endforelse
            </div>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Limits</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                @foreach ($l['limits'] as $dimension => $limit)
                    <div class="cbx-kv" style="padding:9px 0"><dt>{{ ucfirst((string) $dimension) }}</dt><dd class="num">{{ $limit === null ? 'unlimited' : $limit }}</dd></div>
                @endforeach
            </dl>
        </section>
    </div>

    {{-- The minted artifact — the signed, offline-verifiable key. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Minted artifact</h2></header>
        <div style="padding:6px 20px 16px">
            <p class="cbx-page-desc" style="font-size:12px;margin:0 0 6px">The signed license the deployment installs (offline-verifiable). Set it as <span class="num">CBOX_ID_LICENSE_KEY</span>.</p>
            <textarea readonly rows="4" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:11px;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);resize:vertical">{{ $l['key'] }}</textarea>
        </div>
    </section>

    {{-- Issue / renew / revoke history for this deployment. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">History</h2></header>
        <table class="tbl">
            <thead><tr><th style="width:150px">When</th><th style="width:120px">Event</th><th>Detail</th></tr></thead>
            <tbody>
                @foreach ($l['history'] as $event)
                    <tr style="cursor:default">
                        <td class="num mut">{{ $event['at'] ?? '—' }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $event['event'] === 'revoked' ? 'destructive' : ($event['current'] ? 'success' : 'muted') }}">{{ $event['event'] }}</span></td>
                        <td class="mut">{{ $event['detail'] }}@if($event['current'])<span class="cbx-pill cbx-pill--info" style="margin-left:6px">current</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
