<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use App\Models\Experiment;
use App\Models\ExperimentVariant;

/**
 * The deterministic, weighted variant assignment — the heart of "sticky" A/B serving.
 *
 * A visitor is bucketed by a pure function of `(visitorId, experiment key)`:
 *
 *   point  = hash(visitorId ':' experimentKey)  mod  totalWeight
 *   variant = the arm whose cumulative weight window contains `point`
 *
 * The hash is SHA-256 (its top 60 bits taken as an integer — well within PHP's 64-bit signed
 * range, so the modulo never overflows), which spreads visitor ids uniformly across the weight
 * space. Because the function reads only its two inputs and never `rand()`/time/DB state, it is:
 *
 *  - **sticky** — the same visitor always lands on the same variant, so a returning visitor sees
 *    a consistent price (no flapping between refreshes);
 *  - **reproducible & testable** — a fixed set of visitor ids maps to a fixed, assertable split
 *    that tracks the weights within sampling tolerance; there is no seed to lose;
 *  - **weighted** — an arm with twice the weight receives ~twice the traffic.
 *
 * Variants are walked in a stable order (control first, then `sort_order`, then id) so the
 * cumulative windows are identical on every call for a given experiment configuration.
 */
readonly class VariantAssigner
{
    /**
     * The number of top hex characters of the SHA-256 digest folded into the bucket integer.
     * 15 hex chars = 60 bits, whose max (~1.15e18) is safely below PHP_INT_MAX (~9.22e18) on a
     * 64-bit build, so `hexdec()` returns a true int (never a float) and the modulo is exact.
     */
    private const HASH_HEX_CHARS = 15;

    /**
     * The variant `$visitorId` is assigned in `$experiment`, or null when the experiment has no
     * positively-weighted variant to serve (a misconfiguration the caller treats as "don't serve
     * a variant" and falls back to the base table).
     *
     * The experiment's `variants` relation is used as-is (ordered control-first by the model), so
     * load it before calling in a hot path.
     */
    public function assign(Experiment $experiment, string $visitorId): ?ExperimentVariant
    {
        $variants = $this->orderedVariants($experiment);
        $totalWeight = 0;

        foreach ($variants as $variant) {
            $totalWeight += max(0, $variant->weight);
        }

        if ($totalWeight <= 0) {
            return null;
        }

        $point = $this->bucket($visitorId, $experiment->key, $totalWeight);
        $cursor = 0;

        foreach ($variants as $variant) {
            $cursor += max(0, $variant->weight);

            if ($point < $cursor) {
                return $variant;
            }
        }

        // Unreachable while totalWeight > 0: `point` is in `[0, totalWeight)` and the cumulative
        // cursor reaches totalWeight, so some arm always contains it.
        return null;
    }

    /**
     * The deterministic bucket `[0, totalWeight)` for a `(visitor, experiment)` pair. Public so a
     * test (or an operator debugging an assignment) can reproduce the exact bucket a visitor falls
     * in, independent of the variant set.
     */
    public function bucket(string $visitorId, string $experimentKey, int $totalWeight): int
    {
        if ($totalWeight <= 0) {
            return 0;
        }

        $digest = hash('sha256', $visitorId.':'.$experimentKey);
        $n = (int) hexdec(substr($digest, 0, self::HASH_HEX_CHARS));

        return $n % $totalWeight;
    }

    /**
     * The variants in the stable assignment order: control first, then `sort_order`, then id.
     * Mirrors the {@see Experiment::variants()} relation ordering but is applied here too so the
     * assigner is correct even when handed a differently-ordered collection.
     *
     * @return list<ExperimentVariant>
     */
    private function orderedVariants(Experiment $experiment): array
    {
        $variants = $experiment->variants->all();

        usort($variants, static function (ExperimentVariant $a, ExperimentVariant $b): int {
            return [$b->is_control ? 1 : 0, $a->sort_order, $a->id]
                <=> [$a->is_control ? 1 : 0, $b->sort_order, $b->id];
        });

        return $variants;
    }
}
