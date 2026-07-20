@extends('layouts.app')
@section('title', $quote->number)
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Quotes', 'href' => route('billing.quotes')],
        ['label' => $quote->number],
    ]" />
@endsection

@php
    use App\Billing\Support\MoneyFormatter;
    $c = $computation;
    $tone = $quote->status->tone() === 'neutral' ? 'muted' : $quote->status->tone();
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.quotes')" label="Back to quotes" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title num" style="font-size:20px">{{ $quote->number }}
                <span class="cbx-pill cbx-pill--{{ $tone }}">{{ $quote->status->label() }}</span>
                @if ($quote->isExpiredNow() && ! $quote->status->isTerminal())<span class="cbx-pill cbx-pill--muted">past validity</span>@endif
            </h1>
            <p class="cbx-page-desc" style="font-size:13px">
                {{ $quote->customerName() }} · {{ $quote->currency }} · {{ $quote->term_count }} {{ \Illuminate\Support\Str::plural(rtrim($quote->term_unit, 's'), $quote->term_count) }} · billed {{ $quote->billing_interval }}
                @if ($quote->valid_until) · valid until {{ $quote->valid_until->format('Y-m-d') }}@endif
            </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            @if ($quote->isDraft())
                <a href="{{ route('billing.quotes.edit', $quote->id) }}" class="cbx-btn cbx-btn--sm">Edit</a>
                <form method="POST" action="{{ route('billing.quotes.submit', $quote->id) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Submit</button></form>
            @endif
            @if ($quote->status->isApproved())
                <form method="POST" action="{{ route('billing.quotes.send', $quote->id) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Send</button></form>
            @endif
            @if ($quote->status->isSent())
                <form method="POST" action="{{ route('billing.quotes.resend', $quote->id) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Resend</button></form>
            @endif
            @if (in_array($quote->status->value, ['approved', 'sent'], true))
                <form method="POST" action="{{ route('billing.quotes.expire', $quote->id) }}" style="margin:0"
                      data-confirm="Expire {{ $quote->number }}? The customer's order form stops working." data-confirm-title="Expire quote?" data-confirm-label="Expire" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Expire</button></form>
            @endif
            <form method="POST" action="{{ route('billing.quotes.clone', $quote->id) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Clone</button></form>
            @if (! $quote->isProvisioned())
                <form method="POST" action="{{ route('billing.quotes.destroy', $quote->id) }}" style="margin:0"
                      data-confirm="Delete {{ $quote->number }}? This cannot be undone." data-confirm-title="Delete quote?" data-confirm-label="Delete" data-confirm-variant="destructive">
                    @csrf @method('DELETE')
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Delete</button>
                </form>
            @endif
        </div>
    </header>

    @if ($quote->rejection_reason && $quote->isDraft())
        <div class="cbx-panel" style="padding:12px 20px;border-left:3px solid var(--destructive)" role="alert">
            <strong style="color:var(--destructive)">Returned from approval.</strong> <span class="mut">{{ $quote->rejection_reason }}</span>
        </div>
    @endif

    {{-- Deal-desk decision, right on the detail for an approver. --}}
    @if ($quote->status->isPendingApproval())
        <section class="cbx-panel" style="border-left:3px solid var(--warning)">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Awaiting approval</h2><span class="mut" style="font-size:12px">Threshold: {{ $threshold }}</span></header>
            <div style="padding:8px 20px 18px;display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">
                <form method="POST" action="{{ route('billing.quotes.approve', $quote->id) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Approve</button></form>
                <form method="POST" action="{{ route('billing.quotes.reject', $quote->id) }}" style="margin:0;display:flex;gap:8px;align-items:center">
                    @csrf
                    <input name="reason" required maxlength="500" placeholder="Reason for rejection" style="min-width:280px" aria-label="Rejection reason">
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Reject</button>
                </form>
            </div>
        </section>
    @endif

    <div class="cbx-grid-3">
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">First invoice{{ $c->taxPending ? ' (net)' : '' }}</div><div class="num" style="font-size:24px;font-weight:600">{{ MoneyFormatter::money($c->firstInvoiceGross) }}</div><div class="mut" style="font-size:11px">{{ $c->taxPending ? 'tax pending' : MoneyFormatter::money($c->firstInvoiceTax).' tax' }}</div></section>
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Committed value (net)</div><div class="num" style="font-size:24px;font-weight:600">{{ MoneyFormatter::money($c->committedNet) }}</div><div class="mut" style="font-size:11px">over {{ $c->periods }} {{ \Illuminate\Support\Str::plural('period', $c->periods) }}</div></section>
        <section class="cbx-panel" style="padding:16px 20px"><div class="mut" style="font-size:12px">Recurring (net / period)</div><div class="num" style="font-size:24px;font-weight:600">{{ MoneyFormatter::money($c->recurringNet) }}</div><div class="mut" style="font-size:11px">{{ $c->hasCoupon() ? $c->couponDiscount->label : 'no coupon' }}</div></section>
    </div>

    @if ($c->taxPending)
        <div class="cbx-panel" style="padding:12px 20px;border-left:3px solid var(--warning)"><span class="mut" style="font-size:12px">{{ $c->taxNote }}</span></div>
    @endif

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Line items</h2></header>
        <table class="tbl">
            <thead><tr><th>Line</th><th style="width:70px">Qty</th><th style="width:120px">Base</th><th style="width:120px">Discount</th><th style="width:120px">Net</th><th style="width:120px">Tax</th><th style="width:120px">Total</th></tr></thead>
            <tbody>
                @forelse ($c->lines as $line)
                    <tr>
                        <td>{{ $line->label }} @unless($line->recurring)<span class="cbx-pill cbx-pill--muted">one-off</span>@endunless</td>
                        <td class="num">{{ $line->quantity }}</td>
                        <td class="num mut">{{ MoneyFormatter::money($line->baseNet) }}</td>
                        <td class="num">{{ $line->hasDiscount() ? '−'.MoneyFormatter::money($line->discount) : '—' }}</td>
                        <td class="num">{{ MoneyFormatter::money($line->net) }}</td>
                        <td class="num mut">{{ MoneyFormatter::money($line->tax) }}</td>
                        <td class="num" style="font-weight:600">{{ MoneyFormatter::money($line->gross) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'receipt', 'size' => 18, 'sw' => 1.7])</div><h3>No lines yet.</h3><p>Edit the quote to add plan and one-off lines.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Contract terms</h2></header>
            <div style="padding:8px 20px 18px">
                <dl class="cbx-grid-2" style="gap:12px 24px;margin:0">
                    <div><dt class="mut" style="font-size:12px">Term</dt><dd style="margin:2px 0 0">{{ $quote->term_count }} {{ \Illuminate\Support\Str::plural(rtrim($quote->term_unit, 's'), $quote->term_count) }}</dd></div>
                    <div><dt class="mut" style="font-size:12px">Billing</dt><dd style="margin:2px 0 0">{{ ucfirst($quote->billing_interval) }}</dd></div>
                    <div><dt class="mut" style="font-size:12px">Start date</dt><dd style="margin:2px 0 0">{{ $quote->start_date?->format('Y-m-d') ?? 'On acceptance' }}</dd></div>
                    <div><dt class="mut" style="font-size:12px">Minimum commitment</dt><dd style="margin:2px 0 0">{{ $quote->minimum_commitment_minor ? MoneyFormatter::minor($quote->minimum_commitment_minor, $quote->currency).' / period' : 'None' }}</dd></div>
                </dl>
                @if (! empty($quote->ramp))
                    <div style="margin-top:14px"><dt class="mut" style="font-size:12px">Ramp schedule</dt>
                        <dd style="margin:4px 0 0;display:flex;gap:6px;flex-wrap:wrap">
                            @foreach ($quote->ramp as $step)<span class="cbx-pill cbx-pill--muted num">P{{ (int) $step['from_period_index'] + 1 }}+ · {{ MoneyFormatter::minor((int) $step['amount_minor'], $quote->currency) }}</span>@endforeach
                        </dd>
                    </div>
                @endif
                @if ($quote->notes)
                    <div style="margin-top:14px"><dt class="mut" style="font-size:12px">Notes</dt><dd style="margin:4px 0 0;white-space:pre-wrap">{{ $quote->notes }}</dd></div>
                @endif
            </div>
        </section>

        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Deal & lifecycle</h2></header>
            <div style="padding:8px 20px 18px">
                <dl class="cbx-grid-2" style="gap:12px 24px;margin:0">
                    <div><dt class="mut" style="font-size:12px">Owner</dt><dd style="margin:2px 0 0">{{ $quote->owner_name ?? '—' }}</dd></div>
                    <div><dt class="mut" style="font-size:12px">Customer</dt><dd style="margin:2px 0 0">
                        @if ($quote->organization)<a class="cbx-link num" href="{{ route('billing.customers.show', $quote->organization_id) }}">{{ $quote->organization->name }}</a>@else{{ $quote->prospect_name ?? '—' }} <span class="cbx-pill cbx-pill--muted">prospect</span>@endif
                    </dd></div>
                    <div><dt class="mut" style="font-size:12px">Approval</dt><dd style="margin:2px 0 0">{{ $quote->approval_required ? 'Required' : 'Below threshold' }}@if($quote->approved_by_name) · {{ $quote->approved_by_name }}@endif</dd></div>
                    <div><dt class="mut" style="font-size:12px">Sent</dt><dd style="margin:2px 0 0">{{ $quote->sent_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                </dl>

                @if ($quote->token && in_array($quote->status->value, ['sent', 'accepted', 'declined'], true))
                    <div style="margin-top:14px"><dt class="mut" style="font-size:12px">Order form</dt>
                        <dd style="margin:4px 0 0"><a class="cbx-link num" href="{{ route('quote.show', $quote->token) }}" target="_blank" rel="noopener">{{ route('quote.show', $quote->token) }}</a></dd>
                    </div>
                @endif

                @if ($quote->subscription)
                    <div style="margin-top:14px"><dt class="mut" style="font-size:12px">Provisioned subscription</dt>
                        <dd style="margin:4px 0 0"><a class="cbx-link" href="{{ route('billing.subscriptions.show', $quote->subscription_id) }}">#{{ $quote->subscription_id }} — {{ $quote->subscription->plan?->name }}</a> · {{ $quote->provisioned_at?->format('Y-m-d H:i') }}</dd>
                    </div>
                @endif
            </div>
        </section>
    </div>

    @if ($quote->acceptance)
        @php($a = $quote->acceptance)
        <section class="cbx-panel" style="border-left:3px solid var(--success)">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Acceptance record</h2><span class="mut" style="font-size:12px">e-signature by acceptance</span></header>
            <div style="padding:8px 20px 18px">
                <dl class="cbx-grid-3" style="gap:12px 24px;margin:0">
                    <div><dt class="mut" style="font-size:12px">Signed by</dt><dd style="margin:2px 0 0">{{ $a->signer_name }}@if($a->signer_email) · {{ $a->signer_email }}@endif</dd></div>
                    <div><dt class="mut" style="font-size:12px">Accepted at</dt><dd style="margin:2px 0 0">{{ $a->accepted_at->format('Y-m-d H:i:s') }}</dd></div>
                    <div><dt class="mut" style="font-size:12px">From IP</dt><dd style="margin:2px 0 0 " class="num">{{ $a->ip ?? '—' }}</dd></div>
                    <div><dt class="mut" style="font-size:12px">Provider</dt><dd style="margin:2px 0 0">{{ $a->signature_provider === 'null' ? 'In-house (click-through)' : $a->signature_provider }}</dd></div>
                    <div><dt class="mut" style="font-size:12px">Accepted total</dt><dd style="margin:2px 0 0">{{ MoneyFormatter::money($a->acceptedTotal()) }}</dd></div>
                    <div><dt class="mut" style="font-size:12px">Committed value</dt><dd style="margin:2px 0 0">{{ MoneyFormatter::money($a->committedValue()) }}</dd></div>
                </dl>
            </div>
        </section>
    @endif
</div>
@endsection
