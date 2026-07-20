@extends('layouts.app')
@section('title', $invoice->number)
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Invoices', 'href' => route('billing.invoices')],
        ['label' => $invoice->number],
    ]" />
@endsection

@php
    use App\Billing\Invoicing\Enums\InvoiceStatus;
    use App\Billing\Support\Initials;
    use App\Billing\Support\MoneyFormatter;
    use Cbox\Billing\Refund\Enums\RefundReason;
    $c = $invoice->currency;
    $refundable = $invoice->status->isRefundable();
    $voidable = $invoice->status->isVoidable();
    $refundedMinor = $creditNotes->sum('gross_minor');
    $remainingMinor = max(0, $invoice->total_minor - $refundedMinor);
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.invoices')" label="Back to invoices" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title num" style="font-size:20px">{{ $invoice->number }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">Issued by {{ $invoice->seller }} · {{ $invoice->issued_at?->format('Y-m-d') ?? 'draft' }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="cbx-pill cbx-pill--{{ $invoice->status->tone() }}">@if($invoice->status !== InvoiceStatus::Draft)<span class="dot"></span>@endif{{ $invoice->status->value }}</span>
            <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.invoices.pdf', $invoice) }}">Download PDF</a>
            <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.customers.show', $invoice->organization_id) }}">View customer</a>
        </div>
    </header>

    @if ($invoice->isTaxExempt())
        <section class="cbx-panel" style="border-color:var(--success)">
            <div style="padding:14px 20px;display:flex;gap:10px;align-items:center">
                @include('partials.icon', ['name' => 'shield', 'size' => 16, 'sw' => 1.8])
                <div>
                    <div style="font-weight:600;font-size:13px">Tax exempted</div>
                    <div class="mut" style="font-size:12px">{{ $invoice->exemption_reason }}</div>
                </div>
            </div>
        </section>
    @endif

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Bill to</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Customer</dt><dd><a class="cbx-link" href="{{ route('billing.customers.show', $invoice->organization->id) }}" style="display:flex;align-items:center;gap:8px"><span class="avatar-sm" style="width:20px;height:20px;font-size:8px">{{ Initials::of($invoice->organization->name) }}</span>{{ $invoice->organization->name }}</a></dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Email</dt><dd>{{ $invoice->organization->billing_email ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Country</dt><dd>{{ $invoice->organization->billing_country ?? '—' }}</dd></div>
                @if ($invoice->subscription_id)
                    <div class="cbx-kv" style="padding:9px 0"><dt>Subscription</dt><dd><a class="cbx-link num" href="{{ route('billing.subscriptions.show', $invoice->subscription_id) }}">#{{ $invoice->subscription_id }}</a></dd></div>
                @endif
            </dl>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Dates</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Issued</dt><dd class="num">{{ $invoice->issued_at?->format('Y-m-d') ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Due</dt><dd class="num">{{ $invoice->due_at?->format('Y-m-d') ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Paid</dt><dd class="num">{{ $invoice->paid_at?->format('Y-m-d') ?? '—' }}</dd></div>
                @if ($invoice->gateway_reference)
                    <div class="cbx-kv" style="padding:9px 0"><dt>Gateway ref</dt><dd class="num">{{ $invoice->gateway_reference }}</dd></div>
                @endif
            </dl>
        </section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Lines</h2></header>
        <table class="tbl">
            <thead><tr><th>Description</th><th class="right" style="width:80px">Qty</th><th class="right" style="width:140px">Unit</th><th class="right" style="width:150px">Amount</th></tr></thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td class="right num">{{ $line->quantity }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($line->unit_minor, $c) }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($line->amount_minor, $c) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="display:flex;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border)">
            <dl style="margin:0;min-width:260px">
                <div class="cbx-kv" style="padding:6px 0"><dt>Subtotal</dt><dd class="num">{{ MoneyFormatter::minor($invoice->subtotal_minor, $c) }}</dd></div>
                <div class="cbx-kv" style="padding:6px 0"><dt>Tax</dt><dd class="num">{{ MoneyFormatter::minor($invoice->tax_minor, $c) }}</dd></div>
                <div class="cbx-kv" style="padding:6px 0;border-top:1px solid var(--border);margin-top:4px"><dt style="font-weight:600">Total</dt><dd class="num" style="font-weight:600">{{ MoneyFormatter::money($invoice->total()) }}</dd></div>
                @if ($refundedMinor > 0)
                    <div class="cbx-kv" style="padding:6px 0"><dt>Refunded</dt><dd class="num">−{{ MoneyFormatter::minor($refundedMinor, $c) }}</dd></div>
                    <div class="cbx-kv" style="padding:6px 0"><dt>Net after refunds</dt><dd class="num">{{ MoneyFormatter::minor($remainingMinor, $c) }}</dd></div>
                @endif
            </dl>
        </div>
    </section>

    {{-- Credit notes issued against this invoice (refunds / adjustments). --}}
    @if ($creditNotes->isNotEmpty())
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Credit notes</h2></header>
            <table class="tbl">
                <thead><tr><th>Number</th><th>Reason</th><th>Issued</th><th class="right" style="width:150px">Credited</th></tr></thead>
                <tbody>
                    @foreach ($creditNotes as $note)
                        <tr data-href="{{ route('billing.credit-notes.show', $note->id) }}" tabindex="0" role="link" aria-label="Open credit note {{ $note->number }}">
                            <td class="num">{{ $note->number }}</td>
                            <td>{{ str_replace('_', ' ', $note->reason) }}</td>
                            <td class="num">{{ $note->issued_at->format('Y-m-d') }}</td>
                            <td class="right num">−{{ MoneyFormatter::minor($note->gross_minor, $c) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- Lifecycle actions — money moves through the engine; every guard is server-side. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Actions</h2></header>
        <div style="padding:16px 20px;display:flex;flex-direction:column;gap:18px">

            @if (! $invoice->isPaid() && $invoice->status !== InvoiceStatus::Void)
                <form method="POST" action="{{ route('billing.invoices.mark-paid', $invoice) }}" class="cbx-grid-2" style="gap:10px;align-items:end"
                      data-confirm="Record invoice {{ $invoice->number }} as paid offline for {{ MoneyFormatter::money($invoice->total()) }}?"
                      data-confirm-title="Record manual payment?" data-confirm-label="Mark paid" data-confirm-variant="primary">
                    @csrf
                    <label style="{{ $labelStyle }}">Mark paid (offline settlement)
                        <input name="reference" placeholder="Payment reference (optional)" maxlength="190" style="{{ $inputStyle }}"></label>
                    <div><button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Record payment</button></div>
                </form>
            @endif

            @if ($voidable)
                <form method="POST" action="{{ route('billing.invoices.void', $invoice) }}"
                      data-confirm="Void invoice {{ $invoice->number }}? A voided invoice is no longer collectible. This cannot be undone."
                      data-confirm-title="Void invoice?" data-confirm-label="Void" data-confirm-variant="destructive">
                    @csrf
                    <div class="cbx-label" style="margin-bottom:6px">Void</div>
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Void invoice</button>
                </form>
            @endif

            <form method="POST" action="{{ route('billing.invoices.resend', $invoice) }}"
                  data-confirm="Re-queue invoice {{ $invoice->number }} to the billing contact?"
                  data-confirm-title="Resend invoice?" data-confirm-label="Resend" data-confirm-variant="primary">
                @csrf
                <div class="cbx-label" style="margin-bottom:6px">Resend</div>
                <button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">Re-queue invoice email</button>
            </form>

            @if ($refundable && $remainingMinor > 0)
                <form method="POST" action="{{ route('billing.invoices.refund', $invoice) }}" id="refund-form"
                      data-confirm="Issue a refund against {{ $invoice->number }}? A credit note will be issued and the amount reversed."
                      data-confirm-title="Issue refund?" data-confirm-label="Refund" data-confirm-variant="destructive">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="cbx-label" style="margin-bottom:6px">Refund → credit note</div>
                    <div class="cbx-grid-3" style="gap:10px;align-items:end">
                        <label style="{{ $labelStyle }}">Mode
                            <select name="mode" id="refund-mode" style="{{ $inputStyle }}">
                                <option value="full">Full ({{ MoneyFormatter::minor($remainingMinor, $c) }})</option>
                                <option value="partial">Partial</option>
                            </select></label>
                        <label style="{{ $labelStyle }}" id="refund-amount-field">Net amount (minor units of {{ $c }})
                            <input class="num" type="number" name="amount_minor" min="1" max="{{ $remainingMinor }}" placeholder="e.g. 5000" style="{{ $inputStyle }}"></label>
                        <label style="{{ $labelStyle }}">Reason
                            <select name="reason" style="{{ $inputStyle }}">
                                @foreach (RefundReason::cases() as $reason)
                                    <option value="{{ $reason->value }}">{{ ucfirst(str_replace('_', ' ', $reason->value)) }}</option>
                                @endforeach
                            </select></label>
                    </div>
                    <div style="margin-top:10px"><button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Issue refund</button></div>
                </form>
                <script>
                    (function(){
                        var mode = document.getElementById('refund-mode'),
                            field = document.getElementById('refund-amount-field');
                        if (!mode) return;
                        function sync(){ field.style.display = mode.value === 'partial' ? '' : 'none'; }
                        mode.addEventListener('change', sync); sync();
                    })();
                </script>
            @endif
        </div>
    </section>
</div>
@endsection
