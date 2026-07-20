@extends('layouts.app')
@section('title', 'My approval requests')
@section('crumb', 'My requests')

@php
    use App\Billing\Support\MoneyFormatter;
@endphp

@section('screen')
<div class="page">
    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">My requests</h1>
            <p class="cbx-page-desc" style="font-size:13px">Sensitive actions you submitted for approval, and where they stand.</p>
        </div>
        <div><a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.approvals') }}">Pending queue</a></div>
    </header>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr>
                <th style="width:150px">Action</th>
                <th>What</th>
                <th style="width:130px">Amount</th>
                <th style="width:120px">Status</th>
                <th style="width:150px">Submitted</th>
                <th style="width:100px"></th>
            </tr></thead>
            <tbody>
                @forelse ($requests as $request)
                    <tr>
                        <td><a class="cbx-link" href="{{ route('billing.approvals.show', $request->id) }}">{{ $request->action_type->label() }}</a><div class="mut" style="font-size:11px">#{{ $request->id }}</div></td>
                        <td>{{ $request->target_type }} {{ $request->target_id }}@if ($request->reason)<div class="mut" style="font-size:11px">{{ $request->reason }}</div>@endif</td>
                        <td class="num">@if ($request->amount_minor !== null){{ $request->currency ? MoneyFormatter::minor($request->amount_minor, $request->currency) : $request->amount_minor }}@else <span class="mut">—</span> @endif</td>
                        <td><span class="cbx-pill cbx-pill--{{ $request->status->tone() }}">{{ $request->status->label() }}</span></td>
                        <td class="mut">{{ $request->created_at?->diffForHumans() }}</td>
                        <td>
                            @if ($request->status->isPending())
                                <form method="POST" action="{{ route('billing.approvals.cancel', $request->id) }}" style="margin:0" data-confirm="Cancel request #{{ $request->id }}?" data-confirm-label="Cancel request">
                                    @csrf
                                    <button type="submit" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Cancel</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>No requests yet.</h3><p>When you take a sensitive action that needs approval, it appears here until a second operator decides.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $requests->links('partials.pagination') }}
</div>
@endsection
