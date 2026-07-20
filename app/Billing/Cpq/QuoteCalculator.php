<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Coupons\CouponDiscounter;
use App\Billing\Coupons\ValueObjects\CouponDiscount;
use App\Billing\Cpq\Enums\QuoteDiscountKind;
use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\ValueObjects\ComputedLine;
use App\Billing\Cpq\ValueObjects\QuoteComputation;
use App\Billing\Mode\Contracts\BillingClock;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\QuoteLine;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Pricing\CouponApplier;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Subscription\ValueObjects\MinimumCommitment;
use Cbox\Billing\Subscription\ValueObjects\RampSchedule;
use Cbox\Tax\Enums\TaxCategory;

/**
 * Prices a {@see Quote} through the engine so the numbers a rep and a customer see are exactly
 * what the subscription will bill (preview == charge). The flow, all in integer minor units:
 *
 *  1. Resolve each line's base net — a plan line through the engine tier calculator
 *     ({@see Plan::amountFor()}), a custom line as its unit amount × quantity.
 *  2. Apply the per-line discount (percent → {@see Money::proratedBy()}, fixed → floored subtract).
 *  3. Apply the order-level coupon to the RECURRING net through the engine
 *     {@see CouponApplier} (via {@see CouponDiscounter}) and distribute the
 *     discount across the recurring lines proportionally (remainder-safe).
 *  4. Run every line through the engine {@see QuoteBuilder} for the quote's tax context, yielding
 *     the tax-aware per-line net/tax/gross and the FIRST-invoice totals — or a tax-pending quote
 *     when the buyer's jurisdiction cannot be resolved.
 *  5. Project the COMMITTED VALUE over the term through the engine
 *     {@see RampSchedule} (per-period price) and
 *     {@see MinimumCommitment} (per-period floor).
 *
 * The committed value is a NET (pre-tax) projection — the minimum recurring value the customer is
 * contractually obligated to across the term — because tax varies by period and place; the first
 * invoice carries the real tax.
 */
readonly class QuoteCalculator
{
    public function __construct(
        private QuoteBuilder $quotes,
        private CouponDiscounter $coupons,
        private QuoteTaxContextFactory $contexts,
        private BillingClock $clock,
    ) {}

    public function compute(Quote $quote): QuoteComputation
    {
        $currency = $quote->currency;
        $quote->loadMissing(['lines.plan.prices.tiers', 'coupon', 'organization']);

        $lines = $quote->lines->all();

        if ($lines === []) {
            return $this->empty($quote);
        }

        // 1–2: base net + per-line discount, keeping the recurring/one-off split.
        $prepared = [];
        foreach ($lines as $line) {
            $base = $this->baseNet($line, $currency);

            $prepared[] = [
                'line' => $line,
                'base' => $base,
                'net' => $this->applyLineDiscount($base, $line, $currency),
            ];
        }

        // 3: order-level coupon on the recurring net, distributed across recurring lines as a
        // per-line share (keyed by line index) — the shaped $prepared is never mutated.
        [$couponShares, $couponDiscount] = $this->couponShares($prepared, $quote, $currency);

        // The final net per line = the line net less its coupon share.
        $finalNets = [];
        foreach ($prepared as $index => $row) {
            $finalNets[$index] = isset($couponShares[$index]) ? $row['net']->minus($couponShares[$index]) : $row['net'];
        }

        // 4: tax through the engine over the final line nets.
        $context = $this->contexts->forQuote($quote);
        $inputs = [];
        foreach ($prepared as $index => $row) {
            $inputs[] = new LineInput(
                description: $row['line']->label(),
                quantity: 1,
                unitAmount: $finalNets[$index],
                category: TaxCategory::Standard,
            );
        }

        $engineQuote = $this->quotes->build($inputs, $context);

        $computedLines = [];
        $recurringNet = Money::zero($currency);
        $oneOffNet = Money::zero($currency);

        foreach ($prepared as $index => $row) {
            $line = $row['line'];
            $engineLine = $engineQuote->lines[$index];
            $finalNet = $finalNets[$index];

            $computedLines[] = new ComputedLine(
                label: $line->label(),
                quantity: $line->quantity,
                recurring: $line->recurring,
                baseNet: $row['base'],
                // The total reduction from base to final net = the per-line discount + coupon share.
                discount: $row['base']->minus($finalNet),
                net: $engineLine->net,
                tax: $engineLine->tax,
                gross: $engineLine->gross,
                taxNote: $engineLine->taxNote,
            );

            if ($line->recurring) {
                $recurringNet = $recurringNet->plus($finalNet);
            } else {
                $oneOffNet = $oneOffNet->plus($finalNet);
            }
        }

        // 5: committed value over the term through the engine ramp + commitment VOs.
        $committedNet = $this->committedValue($quote, $recurringNet);

        return new QuoteComputation(
            currency: $currency,
            lines: $computedLines,
            recurringNet: $recurringNet,
            oneOffNet: $oneOffNet,
            firstInvoiceNet: $engineQuote->totals->net,
            firstInvoiceTax: $engineQuote->totals->tax,
            firstInvoiceGross: $engineQuote->totals->gross,
            dueNow: $engineQuote->totals->dueNow,
            committedNet: $committedNet,
            periods: $quote->periodCount(),
            taxPending: ! $engineQuote->isTaxResolved(),
            taxNote: $engineQuote->isTaxResolved() ? null : $engineQuote->taxResolution->reason,
            couponDiscount: $couponDiscount,
        );
    }

    private function baseNet(QuoteLine $line, string $currency): Money
    {
        if ($line->type === QuoteLineType::Plan) {
            $plan = $line->plan;

            // Deny-by-default: a plan not priced in the quote currency contributes nothing rather
            // than a fabricated amount (authoring refuses this, so it is a defensive guard).
            if (! $plan instanceof Plan || ! $plan->prices->contains('currency', $currency)) {
                return Money::zero($currency);
            }

            return $plan->amountFor($currency, max(1, $line->quantity));
        }

        return Money::ofMinor($line->unit_amount_minor ?? 0, $currency)->multipliedBy(max(1, $line->quantity));
    }

    private function applyLineDiscount(Money $base, QuoteLine $line, string $currency): Money
    {
        if (! $line->hasDiscount()) {
            return $base;
        }

        $value = $line->discount_value ?? 0;

        if ($line->discount_kind === QuoteDiscountKind::Percent) {
            $percent = max(0, min(100, $value));

            return $base->proratedBy(100 - $percent, 100);
        }

        // Fixed: subtract the fixed minor amount, floored at zero.
        $fixed = Money::ofMinor(min($value, $base->minor()), $currency);

        return $base->minus($fixed);
    }

    /**
     * The order-level coupon's per-line discount share: apply the coupon to the recurring net
     * through the engine coupon applier and distribute the discount across the recurring lines
     * proportionally to their net (remainder-safe). Returns the shares keyed by line index plus the
     * coupon consequence, or an empty map when no coupon applies.
     *
     * @param  list<array{line: QuoteLine, base: Money, net: Money}>  $prepared
     * @return array{0: array<int, Money>, 1: ?CouponDiscount}
     */
    private function couponShares(array $prepared, Quote $quote, string $currency): array
    {
        $coupon = $quote->coupon;

        if ($coupon === null) {
            return [[], null];
        }

        $recurringIndexes = [];
        $recurringNet = Money::zero($currency);

        foreach ($prepared as $index => $row) {
            if ($row['line']->recurring) {
                $recurringIndexes[] = $index;
                $recurringNet = $recurringNet->plus($row['net']);
            }
        }

        if (! $recurringNet->isPositive()) {
            return [[], null];
        }

        $discount = $this->coupons->forCoupon($coupon, $recurringNet, $this->clock->now()->toDateTimeImmutable());

        if ($discount === null || ! $discount->amount->isPositive()) {
            return [[], null];
        }

        // Distribute the discount across the recurring lines proportionally, remainder-safe.
        $weights = array_map(static fn (int $i): int => $prepared[$i]['net']->minor(), $recurringIndexes);
        $allocated = $this->allocateProportional($discount->amount->minor(), $weights);

        $shares = [];
        foreach ($recurringIndexes as $slot => $index) {
            $shares[$index] = Money::ofMinor($allocated[$slot], $currency);
        }

        return [$shares, $discount];
    }

    /**
     * The committed contract value: the minimum NET the customer is obligated to over the term.
     * For each of the term's billing periods the effective recurring is the ramp's amount for that
     * period (or the flat recurring net), floored by the minimum commitment — both computed by the
     * engine value objects, never hand-rolled.
     */
    private function committedValue(Quote $quote, Money $recurringNet): Money
    {
        $periods = $quote->periodCount();
        $ramp = $quote->rampSchedule();
        $commitment = $quote->minimumCommitment();
        $committed = Money::zero($quote->currency);

        for ($index = 0; $index < $periods; $index++) {
            $periodRecurring = $ramp !== null ? $ramp->amountForPeriod($index) : $recurringNet;

            // Floor by the commitment: period total = recurring + true-up shortfall = max(recurring, floor).
            $trueUp = $commitment !== null ? $commitment->trueUp($periodRecurring) : Money::zero($quote->currency);
            $committed = $committed->plus($periodRecurring)->plus($trueUp);
        }

        return $committed;
    }

    /**
     * Split `$total` minor units across `$weights` proportionally, distributing the rounding
     * remainder by largest fractional part so the shares sum to `$total` exactly.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private function allocateProportional(int $total, array $weights): array
    {
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

        $remainder = $total - $allocated;
        // Hand out the remaining units to the largest fractional parts first.
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

    /** A zero computation for a quote with no lines yet (a fresh draft preview). */
    private function empty(Quote $quote): QuoteComputation
    {
        $zero = Money::zero($quote->currency);

        return new QuoteComputation(
            currency: $quote->currency,
            lines: [],
            recurringNet: $zero,
            oneOffNet: $zero,
            firstInvoiceNet: $zero,
            firstInvoiceTax: $zero,
            firstInvoiceGross: $zero,
            dueNow: $zero,
            committedNet: $zero,
            periods: $quote->periodCount(),
            taxPending: false,
            taxNote: null,
            couponDiscount: null,
        );
    }
}
