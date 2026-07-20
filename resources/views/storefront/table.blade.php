{{--
    The PUBLIC embeddable pricing table (#57) — a fully SELF-CONTAINED, CSP-safe document:
    inline CSS + inline JS, no external stylesheet/font/script/host (the same discipline as
    /api/docs). Seller-branded (accent + logo/wordmark from the resolved SellerBranding),
    theme-aware (prefers-color-scheme), mobile-first. Every currency/interval price is
    server-rendered for the default and precomputed into an embedded JSON blob so the toggles
    switch entirely client-side.

        $table = RenderedPricingTable   $mode = 'page' | 'embed'
--}}
@php
    /** @var \App\Billing\Storefront\ValueObjects\RenderedPricingTable $table */
    $b = $table->branding;
    $accent = $b->brandColor;
    $onAccent = $b->onBrandColor();
    $isEmbed = ($mode ?? 'page') === 'embed';
    $cur = $table->defaultCurrency;
    $int = $table->defaultInterval;

    // Real yearly saving, per currency: the best genuine discount of a plan's yearly price
    // against 12× its monthly price. Only a positive, real saving is ever advertised — no
    // fabricated "Save with yearly" when yearly is not actually cheaper (accuracy over hype).
    $yearlySavings = [];
    if ($table->hasIntervalToggle && in_array('month', $table->intervals, true) && in_array('year', $table->intervals, true)) {
        foreach ($table->currencies as $c) {
            $best = 0;
            foreach ($table->columns as $col) {
                $m = $col->offer($c, 'month');
                $y = $col->offer($c, 'year');
                if ($m === null || $y === null || ! $m->available || ! $y->available) {
                    continue;
                }
                $annualized = $m->minor * 12;
                if ($annualized <= 0 || $y->minor >= $annualized) {
                    continue;
                }
                $pct = (int) round(($annualized - $y->minor) / $annualized * 100);
                $best = max($best, $pct);
            }
            $yearlySavings[$c] = $best;
        }
    }

    // The client toggle model — a serialization boundary (JS payload), so an array is correct
    // here. Every (plan, currency, interval) offer is precomputed so no server call is needed.
    $client = ['key' => $table->key, 'default' => ['currency' => $cur, 'interval' => $int], 'columns' => [], 'save' => $yearlySavings];
    foreach ($table->columns as $col) {
        $offers = [];
        foreach ($table->currencies as $c) {
            foreach ($table->intervals as $i) {
                $o = $col->offer($c, $i);
                $offers[$c][$i] = $o === null
                    ? ['f' => '—', 'per' => '', 'cta' => '', 'a' => false]
                    : ['f' => $o->formatted, 'per' => $o->per, 'cta' => $o->ctaUrl, 'a' => $o->available];
            }
        }
        $client['columns'][$col->planKey] = $offers;
    }
    $clientJson = str_replace('</', '<\/', (string) json_encode($client, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $intervalLabel = fn (string $i) => $i === 'year' ? 'Yearly' : 'Monthly';
    $defaultSaving = $yearlySavings[$cur] ?? 0;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $table->name }} · {{ $b->productName }}</title>
<style>
:root {
  --pt-accent: {{ $accent }};
  --pt-on-accent: {{ $onAccent }};
  --pt-bg: #ffffff; --pt-fg: #14161c; --pt-muted: #626b7a; --pt-line: #e6e8ee;
  --pt-card: #ffffff; --pt-soft: #f6f7f9; --pt-shadow: 0 1px 2px rgba(16,20,30,.06), 0 8px 24px rgba(16,20,30,.06);
  --pt-ok: #12854a; --pt-off: #b6bcc7; --pt-radius: 16px;
}
@media (prefers-color-scheme: dark) {
  :root {
    --pt-bg: #0d0f14; --pt-fg: #eceef2; --pt-muted: #9aa4b3; --pt-line: #232733;
    --pt-card: #14171e; --pt-soft: #171b23; --pt-shadow: 0 1px 2px rgba(0,0,0,.4), 0 10px 30px rgba(0,0,0,.35);
    --pt-ok: #3fce7e; --pt-off: #4a5262;
  }
}
* { box-sizing: border-box; }
html, body { margin: 0; }
body {
  background: {{ $isEmbed ? 'transparent' : 'var(--pt-bg)' }};
  color: var(--pt-fg);
  font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
}
.pt-wrap { max-width: 1120px; margin: 0 auto; padding: {{ $isEmbed ? '8px 12px 24px' : '48px 20px 64px' }}; }
.pt-head { text-align: center; margin-bottom: 28px; }
.pt-brand { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.pt-brand img { height: 30px; width: auto; display: block; }
.pt-brand .pt-word { font-weight: 700; font-size: 17px; letter-spacing: -.01em; }
.pt-title { font-size: clamp(24px, 4vw, 34px); font-weight: 800; letter-spacing: -.02em; margin: 6px 0 4px; }
.pt-sub { color: var(--pt-muted); margin: 0; font-size: 15px; }
.pt-controls { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; align-items: center; margin-top: 22px; }
.pt-seg { display: inline-flex; background: var(--pt-soft); border: 1px solid var(--pt-line); border-radius: 999px; padding: 3px; }
.pt-seg button { border: 0; background: transparent; color: var(--pt-muted); font: inherit; font-size: 13px; font-weight: 600;
  padding: 7px 16px; border-radius: 999px; cursor: pointer; transition: background .15s, color .15s; }
.pt-seg button.is-on { background: var(--pt-card); color: var(--pt-fg); box-shadow: 0 1px 2px rgba(16,20,30,.12); }
.pt-save { font-size: 12px; font-weight: 600; color: var(--pt-accent); }
.pt-select { appearance: none; background: var(--pt-soft); border: 1px solid var(--pt-line); color: var(--pt-fg);
  border-radius: 999px; padding: 8px 34px 8px 16px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23808894' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center; }
.pt-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 18px; align-items: stretch; }
.pt-col { position: relative; display: flex; flex-direction: column; background: var(--pt-card); border: 1px solid var(--pt-line);
  border-radius: var(--pt-radius); padding: 24px 22px; box-shadow: var(--pt-shadow); }
.pt-col--featured { border-color: var(--pt-accent); box-shadow: 0 0 0 1px var(--pt-accent), var(--pt-shadow); }
.pt-badge { position: absolute; top: -11px; left: 50%; transform: translateX(-50%); background: var(--pt-accent); color: var(--pt-on-accent);
  font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; padding: 4px 12px; border-radius: 999px; white-space: nowrap; }
.pt-name { font-size: 18px; font-weight: 700; margin: 4px 0 0; }
.pt-highlight { color: var(--pt-muted); font-size: 13px; margin: 6px 0 0; min-height: 18px; }
.pt-price { margin: 18px 0 4px; display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
.pt-amount { font-size: 28px; font-weight: 800; letter-spacing: -.02em; }
.pt-per { color: var(--pt-muted); font-size: 14px; font-weight: 500; }
.pt-cta { margin: 18px 0 0; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  background: var(--pt-accent); color: var(--pt-on-accent); text-decoration: none; font-weight: 650; font-size: 14px;
  padding: 12px 16px; border-radius: 10px; transition: filter .15s, transform .05s; }
.pt-cta:hover { filter: brightness(1.06); }
.pt-cta:active { transform: translateY(1px); }
.pt-cta.is-off { pointer-events: none; opacity: .45; }
/* Non-featured CTAs paint on the foreground colour, so their text must be the background
   colour to stay legible in BOTH themes (white-on-near-white broke in dark mode). */
.pt-col:not(.pt-col--featured) .pt-cta { background: var(--pt-fg); color: var(--pt-bg); }
.pt-col--featured .pt-cta { background: var(--pt-accent); color: var(--pt-on-accent); }
.pt-allow { list-style: none; margin: 20px 0 0; padding: 18px 0 0; border-top: 1px solid var(--pt-line);
  display: flex; flex-direction: column; gap: 9px; flex: 1; }
.pt-allow li { display: flex; align-items: flex-start; gap: 9px; font-size: 13.5px; color: var(--pt-fg); }
.pt-allow svg { flex: 0 0 auto; margin-top: 2px; color: var(--pt-accent); }
.pt-compare { margin-top: 44px; }
.pt-compare h2 { text-align: center; font-size: 20px; font-weight: 750; margin: 0 0 18px; }
.pt-scroll { overflow-x: auto; border: 1px solid var(--pt-line); border-radius: var(--pt-radius); background: var(--pt-card); }
.pt-matrix { border-collapse: collapse; width: 100%; min-width: 520px; font-size: 13.5px; }
.pt-matrix th, .pt-matrix td { padding: 13px 16px; text-align: center; border-bottom: 1px solid var(--pt-line); }
.pt-matrix thead th { position: sticky; top: 0; background: var(--pt-soft); font-weight: 700; font-size: 13px; }
.pt-matrix thead th.is-featured { color: var(--pt-accent); }
.pt-matrix tbody th { text-align: left; font-weight: 600; }
.pt-matrix tbody th small { display: block; color: var(--pt-muted); font-weight: 400; font-size: 12px; margin-top: 2px; }
.pt-matrix tbody tr:last-child th, .pt-matrix tbody tr:last-child td { border-bottom: 0; }
.pt-cell-val { font-weight: 700; }
.pt-cell-off { color: var(--pt-off); }
.pt-check { color: var(--pt-ok); }
.pt-foot { text-align: center; color: var(--pt-muted); font-size: 12px; margin-top: 36px; line-height: 1.6; }
.pt-foot a { color: var(--pt-accent); text-decoration: none; }
.pt-empty { text-align: center; color: var(--pt-muted); padding: 40px; }
@media (max-width: 560px) { .pt-wrap { padding: {{ $isEmbed ? '8px 10px 20px' : '32px 14px 48px' }}; } }
</style>
</head>
<body>
<div class="pt-wrap" data-cbox-pricing>
  <header class="pt-head">
    <div class="pt-brand">
      @if ($b->logoUrl)
        <img src="{{ $b->logoUrl }}" alt="{{ $b->legalName }}">
      @else
        <span class="pt-word">{{ $b->productName }}</span>
      @endif
    </div>
    <h1 class="pt-title">{{ $table->name }}</h1>
    <p class="pt-sub">Choose the plan that fits — upgrade or downgrade anytime.</p>

    @if ($table->hasColumns() && ($table->hasIntervalToggle || count($table->currencies) > 1))
      <div class="pt-controls">
        @if ($table->hasIntervalToggle)
          <div class="pt-seg" role="tablist" aria-label="Billing interval" data-cbox-interval>
            @foreach ($table->intervals as $iv)
              <button type="button" role="tab" data-interval="{{ $iv }}" class="{{ $iv === $int ? 'is-on' : '' }}" aria-selected="{{ $iv === $int ? 'true' : 'false' }}">{{ $intervalLabel($iv) }}</button>
            @endforeach
          </div>
          <span class="pt-save" data-cbox-save @if ($defaultSaving <= 0) hidden @endif>Save up to {{ $defaultSaving }}%</span>
        @endif
        @if (count($table->currencies) > 1)
          <label>
            <span class="pt-sr" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Currency</span>
            <select class="pt-select" data-cbox-currency aria-label="Currency">
              @foreach ($table->currencies as $c)
                <option value="{{ $c }}" @selected($c === $cur)>{{ $c }}</option>
              @endforeach
            </select>
          </label>
        @endif
      </div>
    @endif
    {{-- Announce currency/interval switches to assistive tech (prices update silently otherwise). --}}
    <p data-cbox-status role="status" aria-live="polite" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap"></p>
  </header>

  @if (! $table->hasColumns())
    <p class="pt-empty">No plans are available to show right now.</p>
  @else
    <div class="pt-cols">
      @foreach ($table->columns as $col)
        @php $o = $col->offer($cur, $int); @endphp
        <article class="pt-col {{ $col->featured ? 'pt-col--featured' : '' }}">
          @if ($col->badge)<span class="pt-badge">{{ $col->badge }}</span>@endif
          <h2 class="pt-name">{{ $col->name }}</h2>
          <p class="pt-highlight">{{ $col->highlight }}</p>
          <div class="pt-price">
            <span class="pt-amount" data-plan-amount="{{ $col->planKey }}">{{ $o?->formatted ?? '—' }}</span>
            <span class="pt-per" data-plan-per="{{ $col->planKey }}">{{ $o?->per ?? '' }}</span>
          </div>
          @php $ctaAvailable = $o?->available ?? false; @endphp
          <a class="pt-cta {{ $ctaAvailable ? '' : 'is-off' }}" data-plan-cta="{{ $col->planKey }}" href="{{ $o?->ctaUrl ?: '#' }}" @unless ($ctaAvailable) aria-disabled="true" tabindex="-1" @endunless>
            {{ $table->ctaLabel }}
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </a>
          @if ($col->allowances !== [])
            <ul class="pt-allow">
              @foreach ($col->allowances as $line)
                <li>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                  <span>{{ $line }}</span>
                </li>
              @endforeach
            </ul>
          @endif
        </article>
      @endforeach
    </div>

    @if ($table->featureRows !== [])
      <section class="pt-compare">
        <h2>Compare plans</h2>
        <div class="pt-scroll">
          <table class="pt-matrix">
            <thead>
              <tr>
                <th style="text-align:left">Features</th>
                @foreach ($table->columns as $col)
                  <th class="{{ $col->featured ? 'is-featured' : '' }}">{{ $col->name }}</th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @foreach ($table->featureRows as $row)
                <tr>
                  <th scope="row">{{ $row->name }}@if ($row->description)<small>{{ $row->description }}</small>@endif</th>
                  @foreach ($table->columns as $col)
                    @php $cell = $row->cell($col->planKey); @endphp
                    <td>
                      @if (! $cell->granted)
                        <span class="pt-cell-off" aria-label="Not included">—</span>
                      @elseif ($cell->value !== null)
                        <span class="pt-cell-val">{{ $cell->value }}</span>
                      @else
                        <svg class="pt-check" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-label="Included"><path d="M20 6L9 17l-5-5"/></svg>
                      @endif
                    </td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>
    @endif
  @endif

  <footer class="pt-foot">
    <div>{{ $b->legalLine() }}</div>
    @if ($b->supportUrl || $b->supportEmail)
      <div>
        @if ($b->supportUrl)<a href="{{ $b->supportUrl }}">Support</a>@endif
        @if ($b->supportUrl && $b->supportEmail) · @endif
        @if ($b->supportEmail)<a href="mailto:{{ $b->supportEmail }}">{{ $b->supportEmail }}</a>@endif
      </div>
    @endif
  </footer>
</div>

<script type="application/json" id="cbx-pt-data">{!! $clientJson !!}</script>
@verbatim
<script>
(function () {
  var root = document.querySelector('[data-cbox-pricing]');
  var node = document.getElementById('cbx-pt-data');
  if (!root || !node) return;
  var model = JSON.parse(node.textContent);
  var state = { currency: model.default.currency, interval: model.default.interval };

  function apply() {
    Object.keys(model.columns).forEach(function (plan) {
      var byCur = model.columns[plan] || {};
      var byInt = byCur[state.currency] || {};
      var offer = byInt[state.interval];
      var amount = root.querySelector('[data-plan-amount="' + CSS.escape(plan) + '"]');
      var per = root.querySelector('[data-plan-per="' + CSS.escape(plan) + '"]');
      var cta = root.querySelector('[data-plan-cta="' + CSS.escape(plan) + '"]');
      if (!offer) { if (amount) amount.textContent = '—'; if (per) per.textContent = ''; if (cta) cta.classList.add('is-off'); return; }
      if (amount) amount.textContent = offer.f;
      if (per) per.textContent = offer.per;
      if (cta) {
        if (offer.a && offer.cta) {
          cta.setAttribute('href', offer.cta);
          cta.classList.remove('is-off');
          cta.removeAttribute('aria-disabled');
          cta.removeAttribute('tabindex');
        } else {
          cta.setAttribute('href', '#');
          cta.classList.add('is-off');
          cta.setAttribute('aria-disabled', 'true');
          cta.setAttribute('tabindex', '-1');
        }
      }
    });
    applySave();
  }

  // The "Save up to N%" nudge is a real, per-currency figure (or hidden when yearly is not
  // actually cheaper). Keep it truthful as the currency changes.
  var saveEl = root.querySelector('[data-cbox-save]');
  function applySave() {
    if (!saveEl) return;
    var pct = (model.save && model.save[state.currency]) || 0;
    if (pct > 0) { saveEl.textContent = 'Save up to ' + pct + '%'; saveEl.hidden = false; }
    else { saveEl.hidden = true; }
  }

  var statusEl = root.querySelector('[data-cbox-status]');
  function announce() {
    if (!statusEl) return;
    var iv = state.interval === 'year' ? 'yearly' : 'monthly';
    statusEl.textContent = 'Prices updated — ' + iv + ' billing in ' + state.currency + '.';
  }

  var currency = root.querySelector('[data-cbox-currency]');
  if (currency) currency.addEventListener('change', function () { state.currency = this.value; apply(); announce(); });

  var seg = root.querySelector('[data-cbox-interval]');
  if (seg) {
    seg.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-interval]');
      if (!btn) return;
      state.interval = btn.getAttribute('data-interval');
      seg.querySelectorAll('button').forEach(function (b) {
        var on = b === btn;
        b.classList.toggle('is-on', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      apply();
      announce();
    });
  }

  apply();

  // When embedded, report our height to the host frame so its iframe grows to fit (no scrollbar).
  if (window.parent && window.parent !== window) {
    var post = function () {
      var h = document.documentElement.scrollHeight;
      window.parent.postMessage({ type: 'cbox-pricing-height', key: model.key, height: h }, '*');
    };
    window.addEventListener('load', post);
    window.addEventListener('resize', post);
    if (window.ResizeObserver) { new ResizeObserver(post).observe(document.documentElement); }
    post();
  }
})();
</script>
@endverbatim
</body>
</html>
