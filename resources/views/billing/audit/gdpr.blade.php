@extends('layouts.app')
@section('title', 'GDPR / DSAR')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Audit'],
        ['label' => 'GDPR / DSAR'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">GDPR / DSAR tooling</h1>
            <p class="cbx-page-desc" style="font-size:13px">Data-subject access exports and right-to-be-forgotten erasure, per organization. Both actions are themselves recorded in the audit log.</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- The redact-vs-retain policy, stated honestly --}}
    <section class="cbx-panel" style="margin-bottom:14px;border-left:3px solid var(--warning)">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">What erasure does — and does not — remove</h2></header>
        <div style="padding:6px 20px 16px;font-size:12px;line-height:1.6">
            <p style="margin:0 0 8px"><strong>Redacted (PII → tombstone / deleted):</strong> organization name, billing email, tax id and subdivision; stored tax-exemption certificate documents (deleted from disk); local gateway-customer mappings (detached).</p>
            <p style="margin:0 0 8px"><strong>Retained (statutory retention — de-identified, never hard-deleted):</strong> invoices, credit notes, the ledger, wallet adjustments and payments. These reference the organization only by its opaque id and carry no name/email of their own, so redacting the organization removes the PII while the money trail stays intact and auditable.</p>
            <p style="margin:0;color:var(--muted-foreground)">This is honest erasure: we never claim a subject is "fully erased" while legally-required financial records are retained.</p>
        </div>
    </section>

    {{-- Pick a subject --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Organizations</h2><p class="cbx-panel-desc" style="font-size:12px">Export a subject's data, or erase their PII.</p></div>
            <form method="GET" action="{{ route('billing.audit.gdpr') }}" style="display:flex;gap:8px">
                <input type="text" name="q" value="{{ $search }}" placeholder="Search id or name…" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                <button type="submit" class="cbx-btn cbx-btn--sm">Search</button>
            </form>
        </header>
        @if ($organizations->isEmpty())
            <div class="cbx-empty">
                @if ($search)
                    <div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18])</div>
                    <h3>No matches</h3>
                    <p>No organization matches “{{ $search }}”. Try a different id or name.</p>
                @else
                    <h3>No organizations yet</h3>
                    <p>Data-subject exports and erasure appear here once organizations exist.</p>
                @endif
            </div>
        @else
            <table class="tbl">
                <thead><tr><th>Organization</th><th>Id</th><th>Status</th><th class="right">Actions</th></tr></thead>
                <tbody>
                    @foreach ($organizations as $org)
                        <tr>
                            <td><strong>{{ $org->name }}</strong></td>
                            <td class="mut num" style="font-size:11px">{{ $org->id }}</td>
                            <td>
                                @if ($org->isErased())
                                    <span class="cbx-pill cbx-pill--muted">PII erased</span>
                                @elseif ($org->isSuspended())
                                    <span class="cbx-pill cbx-pill--warning">suspended</span>
                                @else
                                    <span class="cbx-pill cbx-pill--success">active</span>
                                @endif
                            </td>
                            <td class="right" style="white-space:nowrap">
                                <a class="cbx-btn cbx-btn--sm" href="{{ route('billing.audit.gdpr.export', $org->id) }}">Export DSAR bundle</a>
                                @unless ($org->isErased())
                                    <form method="POST" action="{{ route('billing.audit.gdpr.erase', $org->id) }}" style="display:inline"
                                          data-confirm="Erase PII for {{ $org->name }}? Financial records are retained (de-identified). This cannot be undone."
                                          data-confirm-title="Erase PII?" data-confirm-label="Erase PII" data-confirm-variant="destructive">
                                        @csrf
                                        <button type="submit" class="cbx-btn cbx-btn--sm cbx-btn--destructive">Erase PII</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="padding:12px 20px">{{ $organizations->links('partials.pagination') }}</div>
        @endif
    </section>
</div>
@endsection
