{{--
    The hosted paywall page (#57) — a self-contained (inline CSS, no external hosts, CSP-safe),
    seller-branded "upgrade to unlock" panel an app redirects a blocked user to. The required
    plan, its price and the checkout deep-link are the UpgradeGate's output (via PaywallPresenter),
    never recomputed here.

        $paywall = RenderedPaywall   $returnUrl = ?string
--}}
@php
    /** @var \App\Billing\Storefront\ValueObjects\RenderedPaywall $paywall */
    $b = $paywall->branding;
    $accent = $b->brandColor;
    $kindLabel = $paywall->gatedKind === 'usage' ? 'usage limit' : 'feature';
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upgrade required · {{ $b->productName }}</title>
<style>
:root {
  --pw-accent: {{ $accent }};
  --pw-bg: #f4f5f8; --pw-fg: #14161c; --pw-muted: #626b7a; --pw-line: #e6e8ee;
  --pw-card: #ffffff; --pw-soft: #f6f7f9; --pw-shadow: 0 1px 2px rgba(16,20,30,.06), 0 18px 48px rgba(16,20,30,.12);
}
@media (prefers-color-scheme: dark) {
  :root {
    --pw-bg: #0b0d12; --pw-fg: #eceef2; --pw-muted: #9aa4b3; --pw-line: #232733;
    --pw-card: #14171e; --pw-soft: #171b23; --pw-shadow: 0 1px 2px rgba(0,0,0,.4), 0 18px 48px rgba(0,0,0,.5);
  }
}
* { box-sizing: border-box; }
html, body { margin: 0; }
body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
  background: var(--pw-bg); color: var(--pw-fg);
  font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
.pw-card { width: 100%; max-width: 440px; background: var(--pw-card); border: 1px solid var(--pw-line);
  border-radius: 18px; box-shadow: var(--pw-shadow); overflow: hidden; }
.pw-top { padding: 26px 28px 0; text-align: center; }
.pw-brand { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 18px; }
.pw-brand img { height: 26px; }
.pw-brand .pw-word { font-weight: 700; font-size: 15px; }
.pw-lock { width: 46px; height: 46px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
  background: color-mix(in srgb, var(--pw-accent) 14%, transparent); color: var(--pw-accent); margin-bottom: 14px; }
.pw-eyebrow { text-transform: uppercase; letter-spacing: .06em; font-size: 11px; font-weight: 700; color: var(--pw-accent); margin: 0 0 6px; }
.pw-title { font-size: 21px; font-weight: 800; letter-spacing: -.01em; margin: 0 0 8px; }
.pw-lead { color: var(--pw-muted); font-size: 14px; margin: 0; }
.pw-lead b { color: var(--pw-fg); }
.pw-body { padding: 22px 28px 26px; }
.pw-offer { display: flex; align-items: center; justify-content: space-between; gap: 12px;
  background: var(--pw-soft); border: 1px solid var(--pw-line); border-radius: 12px; padding: 14px 16px; margin: 18px 0; }
.pw-offer .pw-plan { font-weight: 700; font-size: 15px; }
.pw-offer .pw-plan small { display: block; color: var(--pw-muted); font-weight: 500; font-size: 12px; margin-top: 2px; }
.pw-offer .pw-amt { font-size: 20px; font-weight: 800; text-align: right; white-space: nowrap; }
.pw-offer .pw-amt small { color: var(--pw-muted); font-weight: 500; font-size: 12px; }
.pw-cta { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; text-decoration: none;
  background: var(--pw-accent); color: #fff; font-weight: 650; font-size: 15px; padding: 13px 16px; border-radius: 11px; transition: filter .15s; }
.pw-cta:hover { filter: brightness(1.06); }
.pw-later { display: block; text-align: center; margin-top: 14px; color: var(--pw-muted); font-size: 13px; text-decoration: none; }
.pw-later:hover { color: var(--pw-fg); }
.pw-none { text-align: center; color: var(--pw-muted); font-size: 14px; margin: 18px 0 4px; }
.pw-foot { padding: 14px 28px; border-top: 1px solid var(--pw-line); text-align: center; color: var(--pw-muted); font-size: 11px; }
</style>
</head>
<body>
<main class="pw-card" role="dialog" aria-labelledby="pw-title">
  <div class="pw-top">
    <div class="pw-brand">
      @if ($b->logoUrl)<img src="{{ $b->logoUrl }}" alt="{{ $b->legalName }}">@else<span class="pw-word">{{ $b->productName }}</span>@endif
    </div>
    <div class="pw-lock" aria-hidden="true">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
    </div>
    <p class="pw-eyebrow">Upgrade required</p>
    <h1 class="pw-title" id="pw-title">Unlock {{ $paywall->gatedLabel }}</h1>
    <p class="pw-lead"><b>{{ $paywall->gatedLabel }}</b> is a {{ $kindLabel }} that isn’t part of your current plan.</p>
  </div>
  <div class="pw-body">
    @if ($paywall->available)
      <div class="pw-offer">
        <div class="pw-plan">{{ $paywall->requiredPlanName }}<small>Everything you have today, plus {{ $paywall->gatedLabel }}</small></div>
        @if ($paywall->priceFormatted)
          <div class="pw-amt">{{ $paywall->priceFormatted }}<small>{{ $paywall->priceInterval }}</small></div>
        @endif
      </div>
      @if ($paywall->checkoutUrl)
        {{-- The caller supplied an authorized checkout session — deep-link straight to it. --}}
        <a class="pw-cta" href="{{ $paywall->checkoutUrl }}">
          Upgrade to {{ $paywall->requiredPlanName }}
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
      @elseif ($b->supportUrl)
        {{-- No session was minted for this public page — send the customer to the seller's own
             authenticated upgrade/checkout entry point rather than a fabricated deep-link. --}}
        <a class="pw-cta" href="{{ $b->supportUrl }}">
          Upgrade to {{ $paywall->requiredPlanName }}
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
      @else
        <p class="pw-none">Sign in to your account to upgrade to {{ $paywall->requiredPlanName }}.</p>
      @endif
    @else
      <p class="pw-none">This capability isn’t available on any plan we currently offer. Please contact us if you need it.</p>
    @endif
    @if ($returnUrl)
      <a class="pw-later" href="{{ $returnUrl }}">Maybe later</a>
    @endif
  </div>
  <div class="pw-foot">{{ $b->legalLine() }}</div>
</main>
</body>
</html>
