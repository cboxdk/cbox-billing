@extends('layouts.app')
@section('title', 'Settings')
@section('crumb', 'Settings')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Settings</h1>
            <p class="cbx-page-desc" style="font-size:13px">Selling entities, tax, payment gateways, API tokens and webhooks</p>
        </div>
    </header>

    {{-- Sellers of record --}}
    <section class="cbx-panel" id="sellers">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Sellers of record</h2></header>
        <table class="tbl">
            <thead><tr><th>Legal name</th><th>Establishment</th><th>Reg. number</th><th>Currency</th><th>Prefix</th><th style="width:80px"></th></tr></thead>
            <tbody>
                @foreach ($sellers as $seller)
                    <tr>
                        <td style="font-weight:500">{{ $seller['legal_name'] }}</td>
                        <td class="num">{{ $seller['establishment'] }}</td>
                        <td class="num mut">{{ $seller['registration_number'] }}</td>
                        <td class="num">{{ $seller['currency'] }}</td>
                        <td class="num mut">{{ $seller['invoice_prefix'] }}</td>
                        <td>@if($seller['is_default'])<span class="cbx-pill cbx-pill--info">default</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Tax registrations --}}
    <section class="cbx-panel" id="tax">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Tax registrations</h2></header>
        <table class="tbl">
            <thead><tr><th>Seller</th><th>Country</th><th>Subdivision</th><th>Scheme</th><th>Number</th></tr></thead>
            <tbody>
                @forelse ($taxRegistrations as $reg)
                    <tr>
                        <td>{{ $reg['seller'] }}</td>
                        <td class="num">{{ $reg['country'] }}</td>
                        <td class="num mut">{{ $reg['subdivision'] ?? '—' }}</td>
                        <td class="mut">{{ $reg['scheme'] }}</td>
                        <td class="num mut">{{ $reg['number'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut" style="padding:20px;text-align:center">No tax registrations configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Payment gateways --}}
    <section class="cbx-panel" id="gateways">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Payment gateways</h2></header>
        @foreach ($gateways as $gateway)
            <div class="cbx-row" style="padding:11px 20px">
                <span style="flex:1;font-size:13px;font-weight:500">{{ $gateway['name'] }}<span class="num mut" style="font-size:11px;margin-left:8px">{{ $gateway['mode'] }}</span></span>
                @if ($gateway['connected'])
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>connected</span>
                @else
                    <span class="cbx-pill cbx-pill--muted">not configured</span>
                @endif
            </div>
        @endforeach
    </section>

    {{-- API tokens --}}
    <section class="cbx-panel" id="tokens">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">API tokens</h2></header>
        <table class="tbl">
            <thead><tr><th>Name</th><th>Scope</th><th>Last used</th><th style="width:110px">Created</th></tr></thead>
            <tbody>
                @forelse ($apiTokens as $token)
                    <tr>
                        <td style="font-weight:500">{{ $token['name'] }}</td>
                        <td class="num mut">{{ $token['scope'] }}</td>
                        <td class="mut">{{ $token['last_used'] }}</td>
                        <td class="num mut">{{ $token['created'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="mut" style="padding:20px;text-align:center">No API tokens issued.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Webhooks --}}
    <section class="cbx-panel" id="webhooks">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Webhooks</h2></header>
        <dl style="margin:0;padding:2px 20px 6px">
            <div class="cbx-kv" style="padding:9px 0"><dt>Settlement endpoint</dt><dd class="num">{{ $webhook['endpoint'] }}</dd></div>
            <div class="cbx-kv" style="padding:9px 0"><dt>Signature header</dt><dd class="num">{{ $webhook['signature_header'] }}</dd></div>
            <div class="cbx-kv" style="padding:9px 0"><dt>Signing secret</dt><dd>@if($webhook['secret_configured'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>configured</span>@else<span class="cbx-pill cbx-pill--warning"><span class="dot"></span>deny-by-default (no secret)</span>@endif</dd></div>
        </dl>
    </section>
</div>
@endsection
