@extends('layouts.app')
@section('title', 'Approval request #'.$request->id)
@section('crumb', 'Approval #'.$request->id)

@php
    use App\Billing\Support\MoneyFormatter;
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.approvals')" label="Back to approvals" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $request->action_type->label() }} <span class="mut">#{{ $request->id }}</span></h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $description?->summary ?? 'Held for approval.' }}</p>
        </div>
        <div><span class="cbx-pill cbx-pill--{{ $request->status->tone() }}">{{ $request->status->label() }}</span></div>
    </header>

    <section class="cbx-panel" style="padding:16px 20px;margin-bottom:14px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px">
            <div><div class="mut" style="font-size:11px">Requested by</div><div>{{ $request->requested_by_name ?? $request->requested_by_sub }}</div></div>
            <div><div class="mut" style="font-size:11px">Amount</div><div class="num">@if ($request->amount_minor !== null){{ $request->currency ? MoneyFormatter::minor($request->amount_minor, $request->currency) : $request->amount_minor }}@else — @endif</div></div>
            <div><div class="mut" style="font-size:11px">Organization</div><div>{{ $request->organization_id ?? '—' }}</div></div>
            <div><div class="mut" style="font-size:11px">Approvals</div><div>{{ $request->approvalCount() }} / {{ $request->required_approvals }}</div></div>
            <div><div class="mut" style="font-size:11px">Policy</div><div style="font-size:12px">{{ $threshold }}</div></div>
        </div>
        @if ($request->reason)<div style="margin-top:12px"><div class="mut" style="font-size:11px">Reason</div><div>{{ $request->reason }}</div></div>@endif
    </section>

    @if ($description && ($description->before || $description->after))
        <section class="cbx-panel" style="padding:16px 20px;margin-bottom:14px">
            <h2 style="font-size:13px;margin:0 0 10px">Effect</h2>
            <table class="tbl">
                <thead><tr><th style="width:200px">Field</th><th>Before</th><th>After</th></tr></thead>
                <tbody>
                    @foreach (array_keys($description->before + $description->after) as $key)
                        <tr>
                            <td class="mut">{{ $key }}</td>
                            <td class="num">{{ var_export($description->before[$key] ?? null, true) }}</td>
                            <td class="num">{{ var_export($description->after[$key] ?? null, true) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($request->status->isPending())
        <section class="cbx-panel" style="padding:16px 20px;margin-bottom:14px">
            <h2 style="font-size:13px;margin:0 0 10px">Decision</h2>
            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">
                <form method="POST" action="{{ route('billing.approvals.approve', $request->id) }}" style="margin:0;display:flex;gap:6px;align-items:center"
                      data-confirm="Approve and execute {{ $request->action_type->label() }} (request #{{ $request->id }})? This runs the action." data-confirm-label="Approve" data-confirm-variant="primary">
                    @csrf
                    <input name="note" maxlength="500" placeholder="Note (optional)" class="cbx-input" style="width:200px" aria-label="Approval note">
                    <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Approve</button>
                </form>
                <form method="POST" action="{{ route('billing.approvals.reject', $request->id) }}" style="margin:0;display:flex;gap:6px;align-items:center"
                      data-confirm="Reject request #{{ $request->id }}? Nothing will be executed." data-confirm-label="Reject">
                    @csrf
                    <input name="note" required maxlength="500" placeholder="Rejection reason" class="cbx-input" style="width:200px" aria-label="Rejection reason">
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Reject</button>
                </form>
            </div>
            <p class="mut" style="font-size:11px;margin-top:8px">A maker cannot approve their own request — a different operator must decide.</p>
        </section>
    @elseif ($request->status->isExecuted() && $request->result)
        <section class="cbx-panel" style="padding:16px 20px;margin-bottom:14px">
            <h2 style="font-size:13px;margin:0 0 10px">Executed</h2>
            <div>{{ $request->result['summary'] ?? 'Done.' }}</div>
            <div class="mut" style="font-size:11px;margin-top:4px">Approved by {{ $request->approved_by_name ?? $request->approved_by_sub }} · {{ $request->executed_at?->diffForHumans() }}</div>
        </section>
    @endif

    <section class="cbx-panel">
        <div style="padding:12px 20px;font-size:13px;font-weight:600">Decision history</div>
        <table class="tbl">
            <thead><tr><th>Operator</th><th style="width:110px">Decision</th><th>Note</th><th style="width:160px">When</th></tr></thead>
            <tbody>
                @forelse ($request->decisions as $decision)
                    <tr>
                        <td>{{ $decision->approver_name ?? $decision->approver_sub }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $decision->decision === 'approve' ? 'success' : 'destructive' }}">{{ ucfirst($decision->decision) }}</span></td>
                        <td class="mut">{{ $decision->note ?? '—' }}</td>
                        <td class="mut">{{ $decision->decided_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="mut" style="padding:14px 20px">No decisions recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
