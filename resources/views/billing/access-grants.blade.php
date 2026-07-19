@extends('layouts.app')
@section('title', 'Access grants')
@section('crumb', 'Access grants')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Access grants</h1>
            <p class="cbx-page-desc" style="font-size:13px">The RBAC eligibility mirror — which Cbox ID subjects hold which role on which billing org. A read-only projection kept fresh by the provisioning webhooks; Cbox ID owns assignment.</p>
        </div>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.access-grants') }}" class="filters" role="search" style="margin-bottom:12px">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter by subject, org or role…" aria-label="Filter access grants"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.access-grants') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $grants->total() }}{{ $search ? ' matching' : '' }} of {{ $total }}</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Organization</th><th>Subject</th><th>Role</th><th style="width:120px">Kind</th><th style="width:120px">Environment</th><th style="width:150px">Updated</th></tr></thead>
            <tbody>
                @forelse ($grants as $grant)
                    <tr data-href="{{ route('billing.customers.show', $grant['org_id']) }}" tabindex="0" role="link" aria-label="Open {{ $grant['org'] }}">
                        <td style="font-weight:500">{{ $grant['org'] }}<span class="num mut" style="font-size:11px;margin-left:6px">{{ $grant['org_id'] }}</span></td>
                        <td class="num">{{ $grant['subject'] }}</td>
                        <td>{{ $grant['role'] ?? '—' }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $grant['kind'] === 'role' ? 'info' : 'muted' }}">{{ $grant['kind'] }}</span></td>
                        <td class="num mut">{{ $grant['environment'] ?? '—' }}</td>
                        <td class="num mut">{{ $grant['updated'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No access grants match “{{ $search }}”. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><h3>No access grants mirrored yet.</h3><p>Grants appear here as Cbox ID provisioning webhooks (member/role events) arrive.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $grants->links('partials.pagination') }}
</div>
@endsection
