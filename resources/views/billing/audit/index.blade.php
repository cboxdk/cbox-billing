@extends('layouts.app')
@section('title', 'Audit log')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Audit'],
        ['label' => 'Audit log'],
    ]" />
@endsection

@php
    use App\Billing\Audit\Enums\AuditAction;
    use App\Billing\Audit\Support\AuditTargetLink;
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Audit log</h1>
            <p class="cbx-page-desc" style="font-size:13px">The tamper-evident, append-only record of every operator action — who did what, to which resource, with the before/after where meaningful. Rows are hash-chained and DB-level immutable.</p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="{{ route('billing.audit.export', ['format' => 'csv']) }}" class="cbx-btn cbx-btn--sm">CSV</a>
            <a href="{{ route('billing.audit.export', ['format' => 'ndjson']) }}" class="cbx-btn cbx-btn--sm">NDJSON</a>
        </div>
    </header>

    @include('partials.flash')

    {{-- Chain-status indicator (from audit:verify) --}}
    <section class="cbx-panel" style="margin-bottom:14px;border-left:3px solid {{ $chain->intact ? 'var(--success)' : 'var(--destructive)' }}">
        <div style="padding:12px 20px;display:flex;align-items:center;gap:10px">
            <span class="cbx-pill {{ $chain->intact ? 'cbx-pill--success' : 'cbx-pill--destructive' }}">
                <span class="dot"></span>{{ $chain->intact ? 'Chain verified' : 'Chain broken' }}
            </span>
            <span class="mut" style="font-size:12px">{{ $chain->summary() }}</span>
            <span class="mut" style="font-size:11px;margin-left:auto">Tamper-evident, not tamper-proof — the chain detects edits, it cannot prove a wholesale rewrite never happened.</span>
        </div>
    </section>

    {{-- Filters --}}
    <section class="cbx-panel">
        <form method="GET" action="{{ route('billing.audit') }}" style="padding:14px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end">
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">Search</span>
                <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="actor, summary, target…" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
            </label>
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">Action</span>
                <select name="action" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                    <option value="">All actions</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </label>
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">Actor (sub)</span>
                <input type="text" name="actor" value="{{ $filters['actor'] }}" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
            </label>
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">Organization</span>
                <input type="text" name="org" value="{{ $filters['organization_id'] }}" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
            </label>
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">From</span>
                <input type="date" name="from" value="{{ $filters['from'] }}" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
            </label>
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">To</span>
                <input type="date" name="to" value="{{ $filters['to'] }}" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
            </label>
            <div style="display:flex;gap:8px">
                <button type="submit" class="cbx-btn cbx-btn--primary">Filter</button>
                <a href="{{ route('billing.audit') }}" class="cbx-btn">Reset</a>
            </div>
        </form>
    </section>

    {{-- The trail --}}
    <section class="cbx-panel">
        @if ($events->isEmpty())
            <p class="mut" style="padding:24px;text-align:center">No audit events match these filters.</p>
        @else
            <table class="tbl">
                <thead><tr><th>#</th><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Summary</th><th>Plane</th></tr></thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr data-href="{{ route('billing.audit.show', $event->id) }}" style="cursor:pointer">
                            <td class="num mut">{{ $event->sequence }}</td>
                            <td class="mut" style="white-space:nowrap">{{ $event->occurred_at->format('Y-m-d H:i:s') }}</td>
                            <td>
                                @if ($event->actor_sub === 'system')
                                    <span class="cbx-pill cbx-pill--muted">system</span>
                                @else
                                    <strong style="font-size:12px">{{ $event->actor_name ?? $event->actor_sub }}</strong>
                                    <br><span class="mut num" style="font-size:11px">{{ $event->actor_sub }}</span>
                                @endif
                            </td>
                            <td><span class="cbx-pill cbx-pill--muted">{{ $event->action }}</span></td>
                            <td class="mut num" style="font-size:11px">
                                @php($link = AuditTargetLink::for($event))
                                @if ($event->target_type)
                                    {{ $event->target_type }}@if ($event->target_id) · {{ Str::limit($event->target_id, 18) }}@endif
                                @else
                                    —
                                @endif
                            </td>
                            <td style="font-size:12px">{{ Str::limit($event->summary, 70) }} @if ($event->hasDiff())<span class="cbx-pill cbx-pill--muted" style="font-size:10px">diff</span>@endif</td>
                            <td><span class="cbx-pill {{ $event->livemode ? 'cbx-pill--success' : 'cbx-pill--warning' }}">{{ $event->livemode ? 'live' : 'test' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="padding:12px 20px">{{ $events->links('partials.pagination') }}</div>
        @endif
    </section>
</div>
@endsection
