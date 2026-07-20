@extends('layouts.app')
@section('title', 'Tax exemptions')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Customers', 'href' => route('billing.customers')],
        ['label' => 'Tax exemptions'],
    ]" />
@endsection

@php
    use App\Billing\Tax\Exemptions\ExemptionJurisdictions;
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Tax exemptions</h1>
            <p class="cbx-page-desc" style="font-size:13px">Who is exempt where. A verified, non-expired certificate zero-rates tax for its jurisdiction only — everything else is still taxed (deny-by-default). Manage each customer's certificates from their detail page.</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="cbx-pill cbx-pill--success">{{ $verifiedCount }} verified</span>
            <span class="cbx-pill cbx-pill--warning">{{ $pendingCount }} pending</span>
        </div>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.tax-exemptions') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter by organization, jurisdiction or certificate…" aria-label="Filter tax exemptions"><kbd class="k">F</kbd></div>
        @if ($search)<a href="{{ route('billing.tax-exemptions') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>@endif
        <span style="margin-left:auto" class="num mut">{{ $certificates->total() }}{{ $search ? ' matching' : '' }} {{ \Illuminate\Support\Str::plural('certificate', $certificates->total()) }}</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Organization</th><th style="width:200px">Jurisdiction</th><th style="width:110px">Type</th><th style="width:160px">Certificate #</th><th style="width:100px">Status</th><th style="width:120px">Expires</th></tr></thead>
            <tbody>
                @forelse ($certificates as $cert)
                    <tr data-href="{{ route('billing.customers.show', $cert->organization_id) }}" tabindex="0" role="link" aria-label="Open {{ $cert->organization?->name ?? $cert->organization_id }}">
                        <td style="font-weight:500">{{ $cert->organization?->name ?? '—' }}<span class="num mut" style="font-size:11px;margin-left:6px">{{ $cert->organization_id }}</span></td>
                        <td>{{ ExemptionJurisdictions::label($cert->jurisdiction) }} <span class="num mut" style="font-size:11px">{{ $cert->jurisdiction }}</span></td>
                        <td>{{ $cert->exemption_type->label() }}</td>
                        <td class="num">{{ $cert->certificate_number }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $cert->status->pill() }}">{{ $cert->status->value }}</span></td>
                        <td class="num mut">{{ $cert->expires_at?->format('Y-m-d') ?? 'no expiry' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18])</div><h3>No matches</h3><p>No certificate matches “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>No exemption certificates yet.</h3><p>Certificates uploaded from a customer's detail page (or their portal) appear here.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $certificates->links('partials.pagination') }}
</div>
@endsection
