<?php

declare(strict_types=1);

namespace App\Billing\Cpq\ValueObjects;

use App\Billing\Coupons\ValueObjects\CouponDiscount;
use App\Models\Quote;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Subscription\ValueObjects\MinimumCommitment;
use Cbox\Billing\Subscription\ValueObjects\RampSchedule;

/**
 * The fully-resolved pricing of a {@see Quote}, computed through the engine
 * quote/pricing so the numbers match what will actually bill:
 *
 *  - {@see $lines} — the per-line tax-aware breakdown ({@see ComputedLine}).
 *  - {@see $firstInvoiceNet}/{@see $firstInvoiceTax}/{@see $firstInvoiceGross} — the amount the
 *    FIRST invoice charges at provisioning (recurring plan lines + any one-off lines), computed by
 *    the engine {@see QuoteBuilder}. {@see $dueNow} is the gross
 *    less any wallet credit.
 *  - {@see $recurringNet} — the standard per-period recurring net (the recurring lines' net).
 *  - {@see $committedNet} — the committed contract value: the minimum NET the customer is
 *    contractually obligated to pay over the whole term, projected across {@see $periods} periods
 *    through the engine {@see RampSchedule} (per-period
 *    price) and floored each period by the engine
 *    {@see MinimumCommitment}.
 *  - {@see $taxPending} — true when the buyer's jurisdiction is not resolvable, so the quote is
 *    net-only with an honest {@see $taxNote} (never a fabricated rate).
 *  - {@see $couponDiscount} — the order-level coupon consequence, when one applies.
 *
 * @property list<ComputedLine> $lines
 */
readonly class QuoteComputation
{
    /**
     * @param  list<ComputedLine>  $lines
     */
    public function __construct(
        public string $currency,
        public array $lines,
        public Money $recurringNet,
        public Money $oneOffNet,
        public Money $firstInvoiceNet,
        public Money $firstInvoiceTax,
        public Money $firstInvoiceGross,
        public Money $dueNow,
        public Money $committedNet,
        public int $periods,
        public bool $taxPending,
        public ?string $taxNote,
        public ?CouponDiscount $couponDiscount,
    ) {}

    /** The largest line-level discount percentage on the quote (for the approval discount gate). */
    public function largestDiscountPercent(): int
    {
        $max = 0;

        foreach ($this->lines as $line) {
            if (! $line->baseNet->isPositive() || ! $line->discount->isPositive()) {
                continue;
            }

            // discount / baseNet as an integer percentage (minor-unit exact, no float drift).
            $percent = intdiv($line->discount->minor() * 100, $line->baseNet->minor());
            $max = max($max, $percent);
        }

        return $max;
    }

    public function hasCoupon(): bool
    {
        return $this->couponDiscount !== null;
    }
}
