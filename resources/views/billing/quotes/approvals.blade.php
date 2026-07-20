@extends('layouts.app')
@section('title', 'Quote approvals')
@section('crumb', 'Quote approvals')

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.quotes')" label="Back to quotes" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Approval queue</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $quotes->total() }} awaiting a deal-desk decision · threshold: {{ $threshold }}</p>
        </div>
    </header>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Quote</th><th>Customer</th><th style="width:110px">Currency</th><th style="width:130px">Owner</th><th style="width:260px">Decision</th></tr></thead>
            <tbody>
                @forelse ($quotes as $quote)
                    <tr>
                        <td><a class="cbx-link num" href="{{ route('billing.quotes.show', $quote->id) }}">{{ $quote->number }}</a></td>
                        <td>{{ $quote->customerName() }}</td>
                        <td class="num mut">{{ $quote->currency }}</td>
                        <td class="mut">{{ $quote->owner_name ?? '—' }}</td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center">
                                <form method="POST" action="{{ route('billing.quotes.approve', $quote->id) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Approve</button></form>
                                <form method="POST" action="{{ route('billing.quotes.reject', $quote->id) }}" style="margin:0;display:flex;gap:5px;align-items:center">
                                    @csrf
                                    <input name="reason" required maxlength="500" placeholder="Reason" style="width:120px" aria-label="Rejection reason">
                                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Reject</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>Nothing to approve.</h3><p>Quotes above the deal-desk threshold land here for review before they can be sent.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $quotes->links('partials.pagination') }}
</div>
@endsection
