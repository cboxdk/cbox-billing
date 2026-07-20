<?php

declare(strict_types=1);

namespace App\Billing\Fx;

use App\Billing\Fx\Enums\FxRateOrigin;
use App\Billing\Fx\ValueObjects\EffectiveRate;
use App\Billing\Fx\ValueObjects\FxRate;
use App\Models\FxRate as FxRateModel;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\JoinClause;

/**
 * Resolves the exchange rate to apply for a `from → to` conversion as of a reporting date,
 * from the persisted `fx_rates` store. This is the single place the as-of and cross-rate
 * policies live, so every converted figure is reproducible and auditable.
 *
 * As-of policy: the effective row for a pair is the one dated ON or NEAREST-BEFORE the
 * requested date (rates published on business days; a weekend/holiday reads back to the last
 * business day). On a tie of date, an `override` row supersedes an `ecb` row.
 *
 * Resolution order for `from → to` (deny-by-default — a pair with no path yields null, never a
 * fabricated number):
 *   1. same currency          → rate 1.
 *   2. a direct stored row     → its rate.
 *   3. the inverse stored row  → 1 / rate (derived).
 *   4. the EUR pivot           → (EUR→to) / (EUR→from) from the two ECB/override legs (derived).
 *
 * All arithmetic is exact {@see BigRational} (fractions), so a cross-rate division loses
 * nothing; the single rounding step happens later, once, when money is converted.
 */
readonly class FxRateRepository
{
    private const PIVOT = 'EUR';

    /**
     * The effective `from → to` rate as of `$asOf`, or null when none can be resolved.
     */
    public function effectiveRate(string $from, string $to, CarbonInterface $asOf): ?EffectiveRate
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $on = CarbonImmutable::instance($asOf)->startOfDay();

        if ($from === $to) {
            return new EffectiveRate($from, $to, BigRational::of(1), $on, FxRateOrigin::Derived, false);
        }

        $direct = $this->storedRate($from, $to, $on);
        if ($direct !== null) {
            return new EffectiveRate($from, $to, BigRational::of($direct->rate), $direct->asOf, $direct->origin, false);
        }

        $inverse = $this->storedRate($to, $from, $on);
        if ($inverse !== null) {
            return new EffectiveRate(
                $from,
                $to,
                BigRational::of($inverse->rate)->reciprocal(),
                $inverse->asOf,
                FxRateOrigin::Derived,
                true,
            );
        }

        return $this->pivotRate($from, $to, $on);
    }

    /**
     * A `from → to` cross-rate via the EUR pivot: `(EUR→to) / (EUR→from)`. Only reached for two
     * non-EUR currencies (the EUR legs are caught by the direct/inverse branches). Null when
     * either leg is missing — the honest "rate unavailable" outcome.
     */
    private function pivotRate(string $from, string $to, CarbonImmutable $on): ?EffectiveRate
    {
        $eurFrom = $this->storedRate(self::PIVOT, $from, $on);
        $eurTo = $this->storedRate(self::PIVOT, $to, $on);

        if ($eurFrom === null || $eurTo === null) {
            return null;
        }

        $rate = BigRational::of($eurTo->rate)->dividedBy(BigRational::of($eurFrom->rate));
        $origin = $eurFrom->origin === FxRateOrigin::Override || $eurTo->origin === FxRateOrigin::Override
            ? FxRateOrigin::Override
            : FxRateOrigin::Derived;

        // A derived rate is only as fresh as its stalest leg.
        $asOf = $eurFrom->asOf->lessThan($eurTo->asOf) ? $eurFrom->asOf : $eurTo->asOf;

        return new EffectiveRate($from, $to, $rate, $asOf, $origin, true);
    }

    /**
     * The stored directed rate for `$base → $quote` effective on/nearest-before `$on`, or null.
     * Newest effective date wins; on a tie an `override` row beats an `ecb` row.
     */
    public function storedRate(string $base, string $quote, CarbonInterface $on): ?FxRate
    {
        $row = FxRateModel::query()
            ->where('base', strtoupper($base))
            ->where('quote', strtoupper($quote))
            ->whereDate('as_of_date', '<=', CarbonImmutable::instance($on)->toDateString())
            ->orderByDesc('as_of_date')
            ->orderByRaw("CASE source WHEN 'override' THEN 0 ELSE 1 END")
            ->first();

        if (! $row instanceof FxRateModel) {
            return null;
        }

        return FxRate::of(
            CarbonImmutable::instance($row->as_of_date)->startOfDay(),
            $row->base,
            $row->quote,
            $row->rate,
            $row->origin(),
        );
    }

    /**
     * The latest stored rate per (base, quote, source) as of `$asOf` — the console rates view's
     * data. Ordered by pair then source for a stable table.
     *
     * Resolved in SQL: a grouped subquery takes MAX(as_of_date) per (base, quote, source) up to
     * `$asOf`, joined back to the row that owns it. The unique `(as_of_date, base, quote, source)`
     * key guarantees exactly one row per group, so the result set is bounded by the number of
     * distinct pairs — never the full rate history — regardless of how deep the table grows.
     *
     * @return list<FxRate>
     */
    public function latestRates(CarbonInterface $asOf): array
    {
        $onDate = CarbonImmutable::instance($asOf)->toDateString();

        $latest = FxRateModel::query()
            ->selectRaw('base, quote, source, MAX(as_of_date) as as_of_date')
            ->whereDate('as_of_date', '<=', $onDate)
            ->groupBy('base', 'quote', 'source');

        $rows = FxRateModel::query()
            ->joinSub($latest, 'latest', function (JoinClause $join): void {
                $join->on('fx_rates.base', '=', 'latest.base')
                    ->on('fx_rates.quote', '=', 'latest.quote')
                    ->on('fx_rates.source', '=', 'latest.source')
                    ->on('fx_rates.as_of_date', '=', 'latest.as_of_date');
            })
            ->orderBy('fx_rates.base')
            ->orderBy('fx_rates.quote')
            ->orderBy('fx_rates.source')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[] = FxRate::of(
                CarbonImmutable::instance($row->as_of_date)->startOfDay(),
                $row->base,
                $row->quote,
                $row->rate,
                $row->origin(),
            );
        }

        return $result;
    }
}
