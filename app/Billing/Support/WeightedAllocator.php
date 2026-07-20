<?php

declare(strict_types=1);

namespace App\Billing\Support;

/**
 * The ONE app-side weighted allocator: split a whole `$total` of minor units across `$weights`
 * proportionally so the parts sum back to `$total` EXACTLY. There is a single remainder policy —
 * the largest-remainder (Hamilton) method: hand the leftover units, one each, to the buckets with
 * the largest fractional parts (ties by original order). This replaced two divergent copies (the
 * quote discount split's own largest-remainder pass and the invoice reconstruction's
 * assign-the-remainder-to-the-last-line pass), so weighted money splits are fair and identical
 * everywhere.
 *
 * A non-positive weight-sum allocates nothing (all zeros) — the deny-nothing degenerate case.
 *
 * TODO: swap to Money::allocateWeighted once engine v0.8.2 is tagged and in composer.lock (the
 * app is on v0.8.1, whose Money has no allocateWeighted yet).
 */
class WeightedAllocator
{
    /**
     * @param  list<int>  $weights
     * @return list<int> one share per weight, in the same order, summing to $total
     */
    public static function allocate(int $total, array $weights): array
    {
        // Clamp negative weights to zero: the largest-remainder split is only well-defined for
        // non-negative weights (a negative weight yields a negative fractional part and can break
        // sum-preservation). A future 3+-line-with-discount invoice whose reconstruction weights
        // include a negated line therefore allocates it nothing rather than corrupting the split.
        $weights = array_map(static fn (int $weight): int => max(0, $weight), $weights);
        $sum = array_sum($weights);

        if ($sum <= 0) {
            return array_fill(0, count($weights), 0);
        }

        $shares = [];
        $fractions = [];
        $allocated = 0;

        foreach ($weights as $slot => $weight) {
            $exact = $total * $weight;
            $base = intdiv($exact, $sum);
            $shares[$slot] = $base;
            $fractions[$slot] = $exact - $base * $sum;
            $allocated += $base;
        }

        // Hand the rounding remainder to the largest fractional parts first (ties: lower index).
        $remainder = $total - $allocated;
        arsort($fractions);

        foreach (array_keys($fractions) as $slot) {
            if ($remainder <= 0) {
                break;
            }

            $shares[$slot]++;
            $remainder--;
        }

        ksort($shares);

        return array_values($shares);
    }

    /**
     * The per-unit minor price for a line: its net split evenly across its quantity (integer
     * division), or the whole net when the quantity is not positive. The one definition of the
     * `unit_minor = net / quantity` idiom the invoice writers share.
     */
    public static function unitMinor(int $netMinor, int $quantity): int
    {
        return $quantity > 0 ? intdiv($netMinor, $quantity) : $netMinor;
    }
}
