{{--
    The PUBLIC hosted order form (CPQ Wave 5) — a fully SELF-CONTAINED, CSP-safe document: inline
    CSS + inline form, no external stylesheet/font/script/host (the same discipline as the
    storefront pricing table and /api/docs). Seller-branded (accent + logo/wordmark from the
    resolved SellerBranding), theme-aware (prefers-color-scheme), mobile-first. Acceptance is an
    e-signature-by-acceptance: typed full name + explicit agreement, POSTed same-origin with CSRF.

        $form = RenderedOrderForm   $token = string
--}}
@php
    use App\Billing\Support\MoneyFormatter;
    /** @var \App\Billing\Cpq\ValueObjects\RenderedOrderForm $form */
    $b = $form->branding;
    $accent = $b->brandColor;
    $c = $form->computation;
    $flash = session('status');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Order form {{ $form->number }} · {{ $b->productName }}</title>
<style>
:root {
  --of-accent: {{ $accent }};
  --of-bg: #f6f7f9; --of-fg: #14161c; --of-muted: #626b7a; --of-line: #e6e8ee;
  --of-card: #ffffff; --of-soft: #f6f7f9; --of-ok: #12854a; --of-warn: #a8600a; --of-bad: #c0362c;
  --of-shadow: 0 1px 2px rgba(16,20,30,.06), 0 8px 24px rgba(16,20,30,.06); --of-radius: 14px;
}
@media (prefers-color-scheme: dark) {
  :root {
    --of-bg: #0d0f14; --of-fg: #eceef2; --of-muted: #9aa4b3; --of-line: #232733;
    --of-card: #14171e; --of-soft: #171b23; --of-ok: #3fce7e; --of-warn: #e0a94a; --of-bad: #ef6b60;
    --of-shadow: 0 1px 2px rgba(0,0,0,.4), 0 10px 30px rgba(0,0,0,.35);
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--of-bg); color: var(--of-fg); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; padding: 32px 16px; }
.of { max-width: 720px; margin: 0 auto; }
.of-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.of-brand img { height: 26px; }
.of-word { font-weight: 700; font-size: 16px; letter-spacing: -0.01em; }
.of-card { background: var(--of-card); border: 1px solid var(--of-line); border-radius: var(--of-radius); box-shadow: var(--of-shadow); overflow: hidden; }
.of-accent-bar { height: 4px; background: var(--of-accent); }
.of-hd { padding: 20px 24px; border-bottom: 1px solid var(--of-line); }
.of-hd h1 { margin: 0; font-size: 18px; letter-spacing: -0.01em; }
.of-hd p { margin: 4px 0 0; font-size: 13px; color: var(--of-muted); }
.of-body { padding: 18px 24px; }
.of-sec-title { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--of-muted); margin: 4px 0 10px; }
table.of-lines { width: 100%; border-collapse: collapse; font-size: 13px; }
table.of-lines th { text-align: left; color: var(--of-muted); font-weight: 500; font-size: 11px; padding: 4px 6px; border-bottom: 1px solid var(--of-line); }
table.of-lines td { padding: 8px 6px; border-bottom: 1px solid var(--of-line); }
table.of-lines td.r, table.of-lines th.r { text-align: right; }
.of-tag { display: inline-block; font-size: 10px; padding: 1px 6px; border-radius: 999px; background: var(--of-soft); color: var(--of-muted); border: 1px solid var(--of-line); }
.of-totals { margin-top: 14px; }
.of-line { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; }
.of-line.total { border-top: 1px solid var(--of-line); margin-top: 6px; padding-top: 12px; font-size: 16px; font-weight: 700; }
.of-line .mut { color: var(--of-muted); }
.of-terms { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; margin-top: 6px; font-size: 13px; }
.of-terms dt { color: var(--of-muted); font-size: 11px; }
.of-terms dd { margin: 2px 0 0; }
.of-note { font-size: 12px; color: var(--of-muted); background: var(--of-soft); border: 1px solid var(--of-line); border-radius: 10px; padding: 10px 12px; margin-top: 14px; }
.of-field { margin-bottom: 12px; }
.of-field label { display: block; font-size: 12px; color: var(--of-muted); margin-bottom: 4px; }
.of-field input[type=text], .of-field input[type=email], .of-field textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--of-line); border-radius: 10px; background: var(--of-bg); color: var(--of-fg); font-size: 14px; }
.of-agree { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; margin: 10px 0 14px; }
.of-btn { display: inline-block; width: 100%; padding: 12px 16px; border: 0; border-radius: 10px; background: var(--of-accent); color: #fff; font-size: 15px; font-weight: 600; cursor: pointer; }
.of-btn.secondary { background: transparent; color: var(--of-muted); border: 1px solid var(--of-line); margin-top: 8px; }
.of-status { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-radius: 12px; font-size: 14px; margin-bottom: 14px; }
.of-status.ok { background: color-mix(in srgb, var(--of-ok) 12%, transparent); color: var(--of-ok); }
.of-status.bad { background: color-mix(in srgb, var(--of-bad) 12%, transparent); color: var(--of-bad); }
.of-status.warn { background: color-mix(in srgb, var(--of-warn) 14%, transparent); color: var(--of-warn); }
.of-foot { text-align: center; font-size: 11px; color: var(--of-muted); margin-top: 18px; }
details.of-decline summary { cursor: pointer; font-size: 12px; color: var(--of-muted); margin-top: 12px; }
@media (max-width: 560px) { .of-terms { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="of">
    <div class="of-brand">
        @if ($b->logoUrl)<img src="{{ $b->logoUrl }}" alt="{{ $b->productName }}">@else<span class="of-word" style="color:var(--of-accent)">{{ $b->productName }}</span>@endif
    </div>

    <div class="of-card">
        <div class="of-accent-bar"></div>
        <div class="of-hd">
            <h1>Order form {{ $form->number }}</h1>
            <p>Prepared for {{ $form->customerName }} · {{ $b->legalLine() }}</p>
        </div>
        <div class="of-body">

            @if ($form->status->isAccepted())
                <div class="of-status ok">@if ($form->acceptance)Accepted by {{ $form->acceptance->signer_name }} on {{ $form->acceptance->accepted_at->format('Y-m-d') }}.@else Accepted.@endif Your subscription is being provisioned.</div>
            @elseif ($form->status->value === 'declined')
                <div class="of-status bad">This order form was declined.</div>
            @elseif (! $form->isActionable())
                <div class="of-status warn">This order form is no longer available{{ $form->expired ? ' — it is past its validity date' : '' }}.</div>
            @endif

            @if (session('error'))
                <div class="of-status bad">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="of-status bad">{{ $errors->first() }}</div>
            @endif

            <p class="of-sec-title">What's included</p>
            <table class="of-lines">
                <thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Net</th><th class="r">Tax</th><th class="r">Total</th></tr></thead>
                <tbody>
                    @foreach ($c->lines as $line)
                        <tr>
                            <td>{{ $line->label }} @unless($line->recurring)<span class="of-tag">one-off</span>@endunless</td>
                            <td class="r">{{ $line->quantity }}</td>
                            <td class="r">{{ MoneyFormatter::money($line->net) }}</td>
                            <td class="r">{{ MoneyFormatter::money($line->tax) }}</td>
                            <td class="r">{{ MoneyFormatter::money($line->gross) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="of-totals">
                @if ($c->hasCoupon())
                    <div class="of-line"><span class="mut">{{ $c->couponDiscount->label }}</span><span class="mut">−{{ MoneyFormatter::money($c->couponDiscount->amount) }}</span></div>
                @endif
                <div class="of-line"><span class="mut">First invoice{{ $c->taxPending ? ' (net — tax added at billing)' : '' }}</span><span></span></div>
                <div class="of-line total"><span>Due at start</span><span>{{ MoneyFormatter::money($c->firstInvoiceGross) }}</span></div>
                <div class="of-line"><span class="mut">Committed contract value (net, over {{ $c->periods }} {{ \Illuminate\Support\Str::plural('period', $c->periods) }})</span><span>{{ MoneyFormatter::money($c->committedNet) }}</span></div>
            </div>

            <p class="of-sec-title" style="margin-top:18px">Contract terms</p>
            <dl class="of-terms">
                <div><dt>Term</dt><dd>{{ $form->termSummary }}</dd></div>
                <div><dt>Starts</dt><dd>{{ $form->startDate?->format('Y-m-d') ?? 'On acceptance' }}</dd></div>
                @if ($form->commitmentLabel)<div><dt>Commitment</dt><dd>{{ $form->commitmentLabel }}</dd></div>@endif
                @if ($form->validUntil)<div><dt>Valid until</dt><dd>{{ $form->validUntil->format('Y-m-d') }}</dd></div>@endif
            </dl>
            @if ($form->rampSteps !== [])
                <div class="of-note"><strong>Price ramp:</strong> @foreach ($form->rampSteps as $step){{ $step['label'] }} — {{ $step['amount'] }}@if(! $loop->last); @endif @endforeach</div>
            @endif
            @if ($form->notes)
                <div class="of-note">{{ $form->notes }}</div>
            @endif

            @if ($form->isActionable())
                <p class="of-sec-title" style="margin-top:20px">Accept this order</p>
                <form method="POST" action="{{ route('quote.accept', $token) }}">
                    @csrf
                    <div class="of-field"><label for="signer_name">Full name</label><input type="text" id="signer_name" name="signer_name" required maxlength="200" autocomplete="name"></div>
                    <div class="of-field"><label for="signer_email">Email (optional)</label><input type="email" id="signer_email" name="signer_email" maxlength="200" autocomplete="email"></div>
                    <label class="of-agree"><input type="checkbox" name="agree" value="1" required> I have authority to accept, and I agree to the terms of this order form on behalf of {{ $form->customerName }}. Typing my name and submitting constitutes my electronic signature.</label>
                    <button type="submit" class="of-btn">Accept &amp; sign</button>
                </form>
                <details class="of-decline">
                    <summary>Decline this order</summary>
                    <form method="POST" action="{{ route('quote.decline', $token) }}" style="margin-top:8px">
                        @csrf
                        <div class="of-field"><label for="reason">Reason (optional)</label><textarea id="reason" name="reason" rows="2" maxlength="500"></textarea></div>
                        <button type="submit" class="of-btn secondary">Decline</button>
                    </form>
                </details>
            @endif
        </div>
    </div>

    <p class="of-foot">Secured by {{ $b->productName }}. This is an electronic order form; acceptance is recorded with a timestamp.</p>
</div>
</body>
</html>
