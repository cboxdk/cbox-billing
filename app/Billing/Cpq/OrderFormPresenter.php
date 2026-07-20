<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Cpq\ValueObjects\RenderedOrderForm;
use App\Billing\Notifications\Branding\BrandingResolver;
use App\Billing\Support\MoneyFormatter;
use App\Models\Quote;
use Cbox\Billing\Money\Money;

/**
 * Projects a {@see Quote} + the live pricing into a {@see RenderedOrderForm} the hosted order-form
 * page renders — branding resolved once through the shared {@see BrandingResolver}, totals through
 * {@see QuoteCalculator}, and the contract terms rendered as human strings. Mirrors the storefront
 * pricing-table presenter so the public page stays self-contained and seller-branded.
 */
readonly class OrderFormPresenter
{
    public function __construct(
        private BrandingResolver $branding,
        private QuoteCalculator $calculator,
    ) {}

    public function present(Quote $quote): RenderedOrderForm
    {
        $quote->loadMissing(['lines.plan', 'organization', 'coupon', 'acceptance']);
        $computation = $this->calculator->compute($quote);

        return new RenderedOrderForm(
            number: $quote->number,
            customerName: $quote->customerName(),
            status: $quote->status,
            expired: $quote->isExpiredNow(),
            branding: $this->branding->forSeller($quote->seller_entity_id),
            currency: $quote->currency,
            computation: $computation,
            termSummary: $this->termSummary($quote),
            startDate: $quote->start_date,
            validUntil: $quote->valid_until,
            commitmentLabel: $this->commitmentLabel($quote),
            rampSteps: $this->rampSteps($quote),
            notes: $quote->notes,
            acceptance: $quote->acceptance,
        );
    }

    private function termSummary(Quote $quote): string
    {
        $unit = $quote->term_count === 1 ? rtrim($quote->term_unit, 's') : $quote->term_unit.'s';
        $interval = $quote->billing_interval === 'yearly' ? 'annually' : 'monthly';

        return sprintf('%d %s, billed %s', $quote->term_count, $unit, $interval);
    }

    private function commitmentLabel(Quote $quote): ?string
    {
        if ($quote->minimum_commitment_minor === null || $quote->minimum_commitment_minor <= 0) {
            return null;
        }

        $per = $quote->billing_interval === 'yearly' ? 'year' : 'month';

        return sprintf('%s minimum per %s', MoneyFormatter::minor($quote->minimum_commitment_minor, $quote->currency), $per);
    }

    /**
     * @return list<array{label: string, amount: string}>
     */
    private function rampSteps(Quote $quote): array
    {
        $ramp = $quote->ramp;

        if (! is_array($ramp) || $ramp === []) {
            return [];
        }

        $steps = [];

        foreach ($ramp as $step) {
            $from = (int) $step['from_period_index'];
            $steps[] = [
                'label' => sprintf('From period %d', $from + 1),
                'amount' => MoneyFormatter::money(Money::ofMinor((int) $step['amount_minor'], $quote->currency)),
            ];
        }

        return $steps;
    }
}
