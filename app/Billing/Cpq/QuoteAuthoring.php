<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\Enums\QuoteStatus;
use App\Billing\Cpq\Exceptions\QuoteActionDenied;
use App\Billing\Cpq\ValueObjects\QuoteDraft;
use App\Billing\Cpq\ValueObjects\QuoteLineDraft;
use App\Models\Plan;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * Authors a {@see Quote} draft: create, edit (draft only), and full-replace its lines + contract
 * terms. A plan line is validated against the quote currency (deny-by-default — a plan not priced
 * in the currency is refused, never billed at a fabricated rate), mirroring the subscription
 * create path. A non-draft quote is immutable to the rep — approval, sending and acceptance move
 * it through its lifecycle via the dedicated services.
 */
readonly class QuoteAuthoring
{
    public function __construct(private QuoteNumberGenerator $numbers) {}

    public function create(QuoteDraft $draft): Quote
    {
        $this->assertPlansPriced($draft);

        return DB::transaction(function () use ($draft): Quote {
            $quote = Quote::query()->create([
                'number' => $this->numbers->next(),
                'status' => QuoteStatus::Draft,
            ] + $this->headerAttributes($draft));

            $this->syncLines($quote, $draft->lines);

            return $quote;
        });
    }

    public function update(Quote $quote, QuoteDraft $draft): Quote
    {
        if (! $quote->isDraft()) {
            throw QuoteActionDenied::notEditable();
        }

        $this->assertPlansPriced($draft);

        return DB::transaction(function () use ($quote, $draft): Quote {
            $quote->update($this->headerAttributes($draft));
            $this->syncLines($quote, $draft->lines);

            return $quote->refresh();
        });
    }

    /**
     * @return array{
     *     organization_id: string|null, prospect_name: string|null, prospect_email: string|null,
     *     seller_entity_id: string|null, currency: string, valid_until: string|null, notes: string|null,
     *     coupon_id: int|null, owner_sub: string|null, owner_name: string|null, term_count: int,
     *     term_unit: string, billing_interval: string, start_date: string|null,
     *     minimum_commitment_minor: int|null, ramp: list<array{from_period_index: int, amount_minor: int}>|null
     * }
     */
    private function headerAttributes(QuoteDraft $draft): array
    {
        $terms = $draft->terms;

        return [
            'organization_id' => $draft->organizationId,
            'prospect_name' => $draft->prospectName,
            'prospect_email' => $draft->prospectEmail,
            'seller_entity_id' => $draft->sellerEntityId,
            'currency' => $draft->currency,
            'valid_until' => $draft->validUntil,
            'notes' => $draft->notes,
            'coupon_id' => $draft->couponId,
            'owner_sub' => $draft->ownerSub,
            'owner_name' => $draft->ownerName,
            'term_count' => $terms->termCount,
            'term_unit' => $terms->termUnit,
            'billing_interval' => $terms->billingInterval,
            'start_date' => $terms->startDate,
            'minimum_commitment_minor' => $terms->minimumCommitmentMinor,
            'ramp' => $terms->ramp,
        ];
    }

    /**
     * @param  list<QuoteLineDraft>  $lines
     */
    private function syncLines(Quote $quote, array $lines): void
    {
        $quote->lines()->delete();

        $order = 0;

        foreach ($lines as $line) {
            $quote->lines()->create([
                'sort_order' => $order++,
                'type' => $line->type,
                'plan_id' => $line->type === QuoteLineType::Plan ? $line->planId : null,
                'description' => $line->description,
                'quantity' => max(1, $line->quantity),
                'unit_amount_minor' => $line->type === QuoteLineType::Custom ? $line->unitAmountMinor : null,
                'discount_kind' => $line->discountKind,
                'discount_value' => $line->discountValue,
                'recurring' => $line->recurring,
            ]);
        }
    }

    private function assertPlansPriced(QuoteDraft $draft): void
    {
        foreach ($draft->lines as $line) {
            if ($line->type !== QuoteLineType::Plan || $line->planId === null) {
                continue;
            }

            $plan = Plan::query()->with('prices')->find($line->planId);

            if ($plan === null) {
                throw QuoteActionDenied::planNotPriced('Plan', $draft->currency);
            }

            if (! $plan->prices->contains('currency', $draft->currency)) {
                throw QuoteActionDenied::planNotPriced($plan->name, $draft->currency);
            }
        }
    }
}
