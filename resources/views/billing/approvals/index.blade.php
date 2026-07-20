@extends('layouts.app')
@section('title', 'Approvals')
@section('crumb', 'Approvals')

@php
    use App\Billing\Support\MoneyFormatter;
@endphp

@section('screen')
<div class="page">
    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Pending approvals</h1>
            <p class="cbx-page-desc" style="font-size:13px">
                {{ $requests->total() }} awaiting a second-operator decision. A maker cannot approve their own request.
            </p>
        </div>
        <div><a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.approvals.mine') }}">My requests</a></div>
    </header>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr>
                <th style="width:150px">Action</th>
                <th>What</th>
                <th style="width:130px">Amount</th>
                <th style="width:150px">Requested by</th>
                <th style="width:320px">Decision</th>
            </tr></thead>
            <tbody>
                @forelse ($requests as $request)
                    @php $desc = $descriptions[$request->id] ?? null; @endphp
                    <tr>
                        <td>
                            <a class="cbx-link" href="{{ route('billing.approvals.show', $request->id) }}">{{ $request->action_type->label() }}</a>
                            <div class="mut" style="font-size:11px">#{{ $request->id }}@if ($request->required_approvals > 1) · {{ $request->approvalCount() }}/{{ $request->required_approvals }} approvals @endif</div>
                        </td>
                        <td>
                            <div>{{ $desc?->summary ?? ($request->target_type.' '.$request->target_id) }}</div>
                            @if ($request->reason)<div class="mut" style="font-size:11px">Reason: {{ $request->reason }}</div>@endif
                        </td>
                        <td class="num">
                            @if ($request->amount_minor !== null)
                                {{ $request->currency ? MoneyFormatter::minor($request->amount_minor, $request->currency) : $request->amount_minor }}
                            @else
                                <span class="mut">—</span>
                            @endif
                        </td>
                        <td class="mut">{{ $request->requested_by_name ?? $request->requested_by_sub }}</td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <form method="POST" action="{{ route('billing.approvals.approve', $request->id) }}" style="margin:0"
                                      data-confirm="Approve and execute {{ $request->action_type->label() }} (request #{{ $request->id }})? This runs the action." data-confirm-label="Approve" data-confirm-variant="primary">
                                    @csrf
                                    <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Approve</button>
                                </form>
                                <form method="POST" action="{{ route('billing.approvals.reject', $request->id) }}" style="margin:0;display:flex;gap:5px;align-items:center"
                                      data-confirm="Reject request #{{ $request->id }}? Nothing will be executed." data-confirm-label="Reject">
                                    @csrf
                                    <input name="note" required maxlength="500" placeholder="Reason" class="cbx-input" style="width:120px" aria-label="Rejection reason">
                                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Reject</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>Nothing to approve.</h3><p>Sensitive actions above their configured threshold land here for a second operator before they take effect.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $requests->links('partials.pagination') }}
</div>
@endsection
