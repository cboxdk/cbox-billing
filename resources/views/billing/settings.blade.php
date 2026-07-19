@extends('layouts.app')
@section('title', 'Settings')
@section('crumb', 'Settings')

@php
    $minted = session('minted_token');
    $btnSm = 'font-size:11px;padding:3px 9px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Settings</h1>
            <p class="cbx-page-desc" style="font-size:13px">Selling entities, tax, payment gateways, API tokens and webhooks</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- A freshly minted API token — shown ONCE (only its hash is stored). --}}
    @if (is_array($minted))
        <section class="cbx-panel" style="margin-bottom:14px;border-left:3px solid var(--success)">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <h2 class="cbx-panel-title" style="font-size:14px">API token “{{ $minted['name'] }}” minted — {{ $minted['scope'] }}</h2>
            </header>
            <div style="padding:6px 20px 16px">
                <p class="cbx-page-desc" style="font-size:12px;margin:0 0 6px">Copy it now — only a hash is stored, so it can never be shown again. Send it as the <span class="num">Authorization: Bearer</span> credential to the management/enforcement API.</p>
                <textarea readonly rows="2" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);resize:vertical">{{ $minted['plaintext'] }}</textarea>
            </div>
        </section>
    @endif

    {{-- Sellers of record --}}
    <section class="cbx-panel" id="sellers">
        <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
            <h2 class="cbx-panel-title" style="font-size:14px">Sellers of record</h2>
            <a href="{{ route('billing.settings.sellers.create') }}" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.8])New seller</a>
        </header>
        <table class="tbl">
            <thead><tr><th>Legal name</th><th>Establishment</th><th>Reg. number</th><th>Currency</th><th>Prefix</th><th style="width:280px"></th></tr></thead>
            <tbody>
                @foreach ($sellers as $seller)
                    <tr style="{{ $seller['archived'] ? 'opacity:.55' : '' }}">
                        <td style="font-weight:500">{{ $seller['legal_name'] }}
                            @if($seller['is_default'])<span class="cbx-pill cbx-pill--info" style="margin-left:6px">default</span>@endif
                            @if($seller['archived'])<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">archived</span>@endif
                            @if($seller['source'] === 'config')<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">config</span>@endif
                        </td>
                        <td class="num">{{ $seller['establishment'] }}</td>
                        <td class="num mut">{{ $seller['registration_number'] }}</td>
                        <td class="num">{{ $seller['currency'] }}</td>
                        <td class="num mut">{{ $seller['invoice_prefix'] }}</td>
                        <td>
                            @if ($seller['source'] === 'config')
                                <span class="mut" style="font-size:11px">Set in config — author a copy to edit here.</span>
                            @else
                                <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:wrap">
                                    <a href="{{ route('billing.settings.sellers.edit', $seller['id']) }}" class="cbx-btn" style="{{ $btnSm }}">Edit</a>
                                    @if (!$seller['is_default'] && !$seller['archived'])
                                        <form method="POST" action="{{ route('billing.settings.sellers.default', $seller['id']) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn" style="{{ $btnSm }}">Make default</button></form>
                                    @endif
                                    @if ($seller['archived'])
                                        <form method="POST" action="{{ route('billing.settings.sellers.unarchive', $seller['id']) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn" style="{{ $btnSm }}">Reinstate</button></form>
                                    @else
                                        <form method="POST" action="{{ route('billing.settings.sellers.archive', $seller['id']) }}" style="margin:0"
                                              data-confirm="Archive {{ $seller['legal_name'] }}? It stops being offered for new invoices; existing invoices are untouched."
                                              data-confirm-title="Archive seller?" data-confirm-label="Archive">@csrf<button type="submit" class="cbx-btn" style="{{ $btnSm }}">Archive</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('billing.settings.sellers.destroy', $seller['id']) }}" style="margin:0"
                                          data-confirm="Delete {{ $seller['legal_name'] }}? Refused while it has issued invoices — archive it instead."
                                          data-confirm-title="Delete seller?" data-confirm-label="Delete" data-confirm-variant="destructive">
                                        @csrf @method('DELETE')<button type="submit" class="cbx-btn" style="{{ $btnSm }};color:var(--destructive)">Delete</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Tax registrations --}}
    <section class="cbx-panel" id="tax">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Tax registrations</h2></header>
        <p class="cbx-page-desc" style="font-size:12px;margin:0;padding:0 20px 10px">The console authors each seller's <strong>registrations</strong> (its VAT/GST nexus per jurisdiction), edited on the seller. Tax <strong>rates</strong> are never authored here — they resolve from the cited rate-source feeds (<span class="num">cboxdk/laravel-tax</span>), so a registered jurisdiction's rate is always the sourced figure.</p>
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
                    <tr><td colspan="5" class="mut" style="padding:20px;text-align:center">No tax registrations configured. Add them on a seller entity above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Payment gateways --}}
    <section class="cbx-panel" id="gateways">
        <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
            <h2 class="cbx-panel-title" style="font-size:14px">Payment gateways</h2>
            <a href="{{ route('billing.settings.gateways') }}" class="cbx-btn cbx-btn--sm">Connection &amp; config</a>
        </header>
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
        <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
            <h2 class="cbx-panel-title" style="font-size:14px">API tokens</h2>
            <a href="{{ route('billing.settings.tokens.create') }}" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.8])New token</a>
        </header>
        <table class="tbl">
            <thead><tr><th>Name</th><th>Scope</th><th>Last used</th><th style="width:110px">Created</th><th style="width:110px"></th></tr></thead>
            <tbody>
                @forelse ($apiTokens as $token)
                    <tr style="{{ $token['revoked'] ? 'opacity:.55' : '' }}">
                        <td style="font-weight:500">{{ $token['name'] }}@if($token['revoked'])<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">revoked</span>@endif</td>
                        <td class="num mut">{{ $token['scope'] }}</td>
                        <td class="mut">{{ $token['last_used'] }}</td>
                        <td class="num mut">{{ $token['created'] }}</td>
                        <td>
                            @unless ($token['revoked'])
                                <form method="POST" action="{{ route('billing.settings.tokens.revoke', $token['id']) }}" style="margin:0;text-align:right"
                                      data-confirm="Revoke “{{ $token['name'] }}”? It stops authenticating immediately and cannot be restored."
                                      data-confirm-title="Revoke API token?" data-confirm-label="Revoke" data-confirm-variant="destructive">
                                    @csrf<button type="submit" class="cbx-btn" style="{{ $btnSm }};color:var(--destructive)">Revoke</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut" style="padding:20px;text-align:center">No API tokens issued.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Webhooks --}}
    <section class="cbx-panel" id="webhooks">
        <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
            <h2 class="cbx-panel-title" style="font-size:14px">Webhooks</h2>
            <a href="{{ route('billing.settings.webhooks') }}" class="cbx-btn cbx-btn--sm">Status &amp; rotation</a>
        </header>
        @foreach ($webhookReceivers as $receiver)
            <div class="cbx-row" style="padding:11px 20px">
                <span style="flex:1;font-size:13px"><span style="font-weight:500">{{ $receiver['name'] }}</span><span class="num mut" style="font-size:11px;margin-left:8px">{{ $receiver['endpoint'] }}</span></span>
                @if ($receiver['configured'])
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>configured</span>
                @else
                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>deny-by-default (no secret)</span>
                @endif
            </div>
        @endforeach
    </section>
</div>
@endsection
