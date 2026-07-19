@extends('layouts.app')
@section('title', 'Licenses')
@section('crumb', 'Licenses')

@php
    $statusPill = ['active' => 'success', 'expiring' => 'warning', 'expired' => 'muted', 'revoked' => 'destructive'];
    $issued = session('issued_license');
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Licenses</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $counts['all'] }} issued · {{ $counts['active'] }} active · {{ $counts['expiring'] }} expiring · {{ $counts['expired'] }} expired · {{ $counts['revoked'] }} revoked</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- The freshly minted artifact — the copy-pasteable key the operator hands off. --}}
    @if (is_array($issued))
        <section class="cbx-panel" style="margin-bottom:14px;border-left:3px solid var(--success)">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <h2 class="cbx-panel-title" style="font-size:14px">License {{ $issued['id'] }} minted — {{ $issued['plan'] }}, expires {{ $issued['expires_at'] }}</h2>
            </header>
            <div style="padding:6px 20px 16px">
                <p class="cbx-page-desc" style="font-size:12px;margin:0 0 6px">Deployment <span class="num">{{ $issued['deployment_id'] }}</span>. Set this as <span class="num">CBOX_ID_LICENSE_KEY</span> in the self-hosted deployment (offline-verifiable — no call home required):</p>
                <textarea readonly rows="4" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:11px;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);resize:vertical">{{ $issued['key'] }}</textarea>
            </div>
        </section>
    @endif

    {{-- Issue a new license --}}
    <section class="cbx-panel" id="issue" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Issue a license</h2></header>
        @if (! $signingConfigured)
            <div style="padding:6px 20px 16px">
                <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>no signing key</span>
                <p class="cbx-page-desc" style="font-size:12px;margin:8px 0 0">Set <span class="num">CBOX_LICENSE_SIGNING_KEY</span> before issuing. Generate a keypair with <span class="num">php artisan billing:license-keygen</span>.</p>
            </div>
        @else
            <form method="POST" action="{{ route('billing.licenses.issue') }}" class="cbx-grid-2" style="padding:8px 20px 18px;gap:12px;align-items:end">
                @csrf
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Customer
                    <select name="customer_id" required style="height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px">
                        <option value="">Select an organization…</option>
                        @foreach ($organizations as $org)
                            <option value="{{ $org->id }}">{{ $org->name }} ({{ $org->id }})</option>
                        @endforeach
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Licensable plan
                    <select name="plan" required style="height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px">
                        <option value="">Select a plan…</option>
                        @foreach ($licensablePlans as $plan)
                            <option value="{{ $plan['key'] }}">{{ $plan['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Deployment id <span class="mut" style="font-weight:400">(optional — generated when blank)</span>
                    <input name="deployment_id" placeholder="dep_…" style="height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px">
                </label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Licensed domain <span class="mut" style="font-weight:400">(optional pin)</span>
                    <input name="licensed_domain" placeholder="id.example.com" style="height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px">
                </label>
                <div style="grid-column:1 / -1">
                    <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'key', 'size' => 14, 'sw' => 1.7])Mint license</button>
                </div>
            </form>
        @endif
    </section>

    {{-- Issued licenses --}}
    <form method="GET" action="{{ route('billing.licenses') }}" class="filters" role="search" style="margin-bottom:12px">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter by customer, deployment or plan…" aria-label="Filter licenses"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.licenses') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $licenses->total() }}{{ $search ? ' matching' : '' }} of {{ $counts['all'] }}</span>
    </form>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Issued licenses</h2></header>
        <table class="tbl">
            <thead><tr><th>Customer</th><th>Deployment</th><th>Plan</th><th>Entitlements</th><th>Expires</th><th>Status</th><th style="width:150px"></th></tr></thead>
            <tbody>
                @forelse ($licenses as $license)
                    <tr data-href="{{ route('billing.licenses.show', ['id' => $license['id']]) }}" tabindex="0" role="link" aria-label="Open license {{ $license['id'] }}">
                        <td style="font-weight:500">{{ $license['customer_id'] }}</td>
                        <td class="num mut">{{ $license['deployment_id'] }}</td>
                        <td class="num">{{ $license['plan'] }}</td>
                        <td class="mut" style="font-size:11px">{{ count($license['entitlements']) }} · {{ implode(', ', array_slice($license['entitlements'], 0, 3)) }}{{ count($license['entitlements']) > 3 ? '…' : '' }}</td>
                        <td class="num mut">{{ $license['expires_at']->format('Y-m-d') }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $statusPill[$license['status']] ?? 'muted' }}">{{ $license['status'] }}</span></td>
                        <td>
                            <div style="display:flex;gap:6px;justify-content:flex-end">
                                @if ($license['status'] !== 'revoked')
                                    <form method="POST" action="{{ route('billing.licenses.renew', ['id' => $license['id']]) }}" style="margin:0">
                                        @csrf
                                        <button type="submit" class="cbx-btn" style="font-size:11px;padding:3px 9px">Renew</button>
                                    </form>
                                    <form method="POST" action="{{ route('billing.licenses.revoke', ['id' => $license['id']]) }}" style="margin:0"
                                          data-confirm="Revoke license {{ $license['id'] }}? It will be refused once the new revocation list is pulled. This cannot be undone."
                                          data-confirm-title="Revoke license?" data-confirm-label="Revoke license">
                                        @csrf
                                        <button type="submit" class="cbx-btn" style="font-size:11px;padding:3px 9px;color:var(--destructive)">Revoke</button>
                                    </form>
                                @else
                                    <span class="mut" style="font-size:11px">revoked</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No issued licenses match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><h3>No licenses issued yet.</h3><p>Licenses you mint for self-hosted deployments will appear here.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $licenses->links('partials.pagination') }}
</div>
@endsection
