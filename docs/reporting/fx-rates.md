---
title: FX rates
description: The ECB euro reference-rate feed adapter (cited source), operator overrides, the fx_rates store, and the as-of / cross-rate / rounding policy — no rate is ever fabricated.
weight: 10
---

# FX rates

Consolidated reporting normalizes every currency to one reporting currency. The rates that make
that possible are stored in the `fx_rates` table and come from exactly two kinds of source — both
honest, neither invented.

## Sources

### ECB euro foreign-exchange reference rates (cited)

The primary source is the **European Central Bank's "Euro foreign exchange reference rates"**, a
free, public, citable feed:

> Source: European Central Bank — Euro foreign exchange reference rates
> `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml`

The `EcbFxRateSource` fetches that XML and `EcbRatesParser` parses it (native DOM, no third-party
XML dependency) into rows with **base EUR** — e.g. `EUR → USD`, `EUR → DKK`. The ECB publishes the
set once per TARGET business day around 16:00 CET; the feed is base-EUR only, so non-EUR pairs are
**derived** at read time (see below), never stored as invented rows.

### Operator / treasury overrides

`StaticFxRateSource` reads `billing.fx.overrides` — directed rates an operator authors by hand for
a pair the ECB does not publish (an exotic currency) or to pin a treasury-agreed rate. Each entry
is `{date?, base, quote, rate}` (`date` defaults to today). The FX-rates console page can author
the same rows into `fx_rates`. An override **supersedes** ECB on the same `(date, pair)`.

Both sources are deny-by-default: they emit only rates they genuinely have. A malformed override is
skipped, not guessed.

## The `fx_rates` store

One row per directed quote `1 base = rate quote`, effective on `as_of_date`, from a named `source`
(`ecb` / `override`). `rate` is an exact decimal **string** (never a float), so a source's published
precision survives ingestion. This is global operator reference data (a rate is not a tenant's
property), so the table carries no `livemode` column.

## Resolution policy (the auditable part)

`FxRateRepository::effectiveRate(from, to, asOf)` resolves a rate, in order:

1. **Same currency** → rate `1`.
2. **Direct** stored row `from → to` → its rate.
3. **Inverse** stored row `to → from` → `1 / rate` (marked *derived*).
4. **EUR pivot** cross-rate → `(EUR→to) / (EUR→from)` from the two ECB/override legs (*derived*).
5. Otherwise → **unavailable** (`null`). The honest failure — never a fabricated number.

- **As-of:** the effective row for a pair is the one dated **on or nearest-before** the requested
  date (a weekend/holiday reads back to the last business day). On a date tie an `override` beats
  an `ecb` row. A derived cross-rate is only as fresh as its **stalest** leg.
- **Exactness:** all rate arithmetic is exact `Brick\Math\BigRational` (fractions), so a cross-rate
  division loses nothing.

## Conversion (rounding policy)

`FxConverter::convert(Money, toCurrency, asOf)` returns the native amount, the converted amount, and
the exact `EffectiveRate` applied. It multiplies the amount by the exact rate fraction and rounds
**once, half-up**, to the target currency's minor unit. No intermediate float, no early rounding —
the result is a pure function of `(amount, pair, date)`. `tryConvert(...)` returns `null` instead of
throwing when a rate is unavailable, for callers that surface that as a first-class outcome.

## Refresh

`php artisan fx:refresh` pulls every configured source and upserts into `fx_rates` (a re-run
refreshes, never duplicates). It is scheduled on weekdays after the ECB publishes. A source failure
(e.g. the ECB fetch is down) is reported per source and never blocks the others — the store keeps
serving the last good rates under the nearest-before as-of policy. Manage it in the console at
**Settings → FX rates** (`settings:manage`), which shows the current rates with their source and
as-of date, runs a refresh, and authors overrides.

## Configuration

```php
// config/billing.php
'fx' => [
    'sources' => ['ecb', 'override'],
    'ecb' => ['url' => env('CBOX_BILLING_FX_ECB_URL', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml')],
    'overrides' => [
        // ['date' => '2026-07-20', 'base' => 'USD', 'quote' => 'XOF', 'rate' => '600.0'],
    ],
],
```
