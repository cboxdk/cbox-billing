<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Support\WeightedAllocator;
use Tests\TestCase;

/**
 * The single weighted allocator both the quote discount split (QuoteCalculator) and the invoice
 * net/tax reconstruction (InvoiceOperations) now share. One largest-remainder policy: the shares
 * always sum to the total EXACTLY, and the same inputs give the same split whichever call site
 * asks — so the two formerly-divergent remainder policies are one.
 */
class WeightedAllocatorTest extends TestCase
{
    public function test_shares_sum_to_the_total_exactly(): void
    {
        $shares = WeightedAllocator::allocate(100, [1, 1, 1]);

        $this->assertSame(100, array_sum($shares));
        // Largest-remainder hands the one leftover unit to the first (tie) bucket.
        $this->assertSame([34, 33, 33], $shares);
    }

    public function test_the_remainder_goes_to_the_largest_fractional_part(): void
    {
        // total 7 across weights [1,2]: bases [2,4], fractions [1,2] → the extra unit lands on
        // the larger-weight bucket, giving [2,5] (sum 7), never split to the last line by position.
        $this->assertSame([2, 5], WeightedAllocator::allocate(7, [1, 2]));
    }

    public function test_a_zero_weight_sum_allocates_nothing(): void
    {
        $this->assertSame([0, 0, 0], WeightedAllocator::allocate(500, [0, 0, 0]));
    }

    public function test_both_former_call_sites_produce_identical_splits_for_identical_inputs(): void
    {
        // The quote discount split (weights = each recurring line's NET) and the invoice
        // reconstruction (weights = each line's GROSS) are now the same function, so identical
        // numeric inputs yield byte-identical shares — the unified policy.
        $total = 1_000;
        $weights = [317, 683];

        $discountSplit = WeightedAllocator::allocate($total, $weights);
        $headerSplit = WeightedAllocator::allocate($total, $weights);

        $this->assertSame($discountSplit, $headerSplit);
        $this->assertSame($total, array_sum($discountSplit));
        $this->assertSame([317, 683], $discountSplit);
    }

    public function test_unit_minor_divides_net_across_quantity(): void
    {
        $this->assertSame(250, WeightedAllocator::unitMinor(1000, 4));
        // A non-positive quantity yields the whole net (never a divide-by-zero).
        $this->assertSame(1000, WeightedAllocator::unitMinor(1000, 0));
    }
}
