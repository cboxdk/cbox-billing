<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Billing\Catalog\Contracts\AuthorsPlanPrices;
use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\Exceptions\CatalogAuthoringException;
use App\Billing\Catalog\ValueObjects\PlanPriceDraft;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanPriceTier;
use App\Models\Subscription;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Exceptions\MalformedTierSet;
use Cbox\Billing\Catalog\Pricing\TierCalculator;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;
use Cbox\Billing\Money\Money;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Persists an authored plan price, validating its tier set against the SAME rules the
 * engine bills with so a saved price always prices. The check is two-layered: explicit
 * shape rules give a friendly, field-level reason ("the last tier must be unbounded"),
 * and the price is then run through the real engine {@see TierCalculator} as the final
 * arbiter — the app can never drift from the engine's definition of a valid tier set.
 *
 * A flat/per-unit price carries no tiers (the base amount is the whole story); the four
 * tiered models carry an ordered {@see PlanPriceTier} set, and `package` additionally a
 * positive size with a block price on its first tier.
 */
readonly class PlanPriceAuthoring implements AuthorsPlanPrices
{
    public function __construct(private ConnectionInterface $db) {}

    public function save(PlanPriceDraft $draft): PlanPrice
    {
        if (! Plan::query()->whereKey($draft->planId)->exists()) {
            throw CatalogAuthoringException::unknownPlan($draft->planId);
        }

        // Flat/per-unit price purely from the base amount; only the tiered models keep tiers.
        $tiers = $draft->model->isTiered() ? $draft->tiers : [];

        $this->assertPrices($draft, $tiers);

        return $this->db->transaction(function () use ($draft, $tiers): PlanPrice {
            $price = PlanPrice::query()->updateOrCreate(
                ['plan_id' => $draft->planId, 'currency' => $draft->currency],
                [
                    'price_minor' => $draft->priceMinor,
                    'pricing_model' => $draft->model->value,
                    'package_size' => $draft->model === PricingModel::Package ? $draft->packageSize : null,
                ],
            );

            // Replace the tier set wholesale: the draft is the authoritative new schedule.
            $price->tiers()->delete();

            foreach ($tiers as $order => $tier) {
                PlanPriceTier::query()->create([
                    'plan_price_id' => $price->id,
                    'up_to' => $tier['up_to'],
                    'unit_minor' => $tier['unit_minor'],
                    'flat_minor' => $tier['flat_minor'],
                    'sort_order' => $order,
                ]);
            }

            return $price->load('tiers');
        });
    }

    public function delete(PlanPrice $price): void
    {
        $plan = $price->plan;

        // Currency-lock guard: an active plan's serving subscribers whose org is billed in
        // this price's currency are grandfathered onto it — removing it would strip the
        // currency out from under them. A legacy (inactive) plan takes no new signups, but a
        // grandfathered subscriber may still be serving on it, so the guard holds there too.
        if ($plan instanceof Plan) {
            $onCurrency = Subscription::query()
                ->where('plan_id', $plan->id)
                ->serving()
                ->whereRelation('organization', 'billing_currency', $price->currency)
                ->count();

            if ($onCurrency > 0) {
                throw CatalogActionDenied::priceInUse($plan->name, $price->currency, $onCurrency);
            }
        }

        $this->db->transaction(function () use ($price): void {
            $price->tiers()->delete();
            $price->delete();
        });
    }

    /**
     * Reject a draft that would not price. Non-tiered models need nothing beyond a
     * non-negative base amount (already validated upstream). Tiered models must satisfy the
     * engine's tier rules — enforced here explicitly for good messages, then re-checked by
     * running the projected {@see Price} through the engine {@see TierCalculator}.
     *
     * @param  list<array{up_to: int|null, unit_minor: int, flat_minor: int|null}>  $tiers
     */
    private function assertPrices(PlanPriceDraft $draft, array $tiers): void
    {
        if (! $draft->model->isTiered()) {
            return;
        }

        if ($tiers === []) {
            throw CatalogAuthoringException::emptyTiers();
        }

        $lastIndex = count($tiers) - 1;
        $previousBound = 0;

        foreach ($tiers as $index => $tier) {
            if ($tier['unit_minor'] < 0 || ($tier['flat_minor'] !== null && $tier['flat_minor'] < 0)) {
                throw CatalogAuthoringException::negativeAmount();
            }

            $isLast = $index === $lastIndex;

            if ($tier['up_to'] === null) {
                // An unbounded tier is only ever the final one.
                if (! $isLast) {
                    throw CatalogAuthoringException::boundsMustAscend();
                }

                continue;
            }

            // A bounded final tier leaves the top of the schedule uncovered.
            if ($isLast) {
                throw CatalogAuthoringException::finalTierMustBeUnbounded();
            }

            if ($tier['up_to'] <= $previousBound) {
                throw CatalogAuthoringException::boundsMustAscend();
            }

            $previousBound = $tier['up_to'];
        }

        if ($draft->model === PricingModel::Package) {
            if ($draft->packageSize === null || $draft->packageSize <= 0) {
                throw CatalogAuthoringException::packageNeedsSize();
            }

            if ($tiers[0]['flat_minor'] === null) {
                throw CatalogAuthoringException::packageNeedsBlockPrice();
            }
        }

        $this->assertEnginePrices($draft, $tiers);
    }

    /**
     * The final arbiter: project the draft into the engine {@see Price} and price a probe
     * quantity through the real {@see TierCalculator}. Any rejection the explicit rules did
     * not catch (currency mismatch, an engine-specific guard) surfaces verbatim.
     *
     * @param  list<array{up_to: int|null, unit_minor: int, flat_minor: int|null}>  $tiers
     */
    private function assertEnginePrices(PlanPriceDraft $draft, array $tiers): void
    {
        $priceTiers = array_map(
            fn (array $tier): PriceTier => new PriceTier(
                upTo: $tier['up_to'],
                unitAmount: Money::ofMinor($tier['unit_minor'], $draft->currency),
                flatAmount: $tier['flat_minor'] !== null ? Money::ofMinor($tier['flat_minor'], $draft->currency) : null,
            ),
            $tiers,
        );

        $price = new Price(
            id: 'draft',
            productId: (string) $draft->planId,
            model: $draft->model,
            unitAmount: Money::ofMinor($draft->priceMinor, $draft->currency),
            effectiveFrom: new DateTimeImmutable('@0'),
            packageSize: $draft->packageSize,
            tiers: $priceTiers,
        );

        // A probe of one unit runs the engine's full tier-set validation before pricing;
        // the final tier is unbounded (asserted above) so every quantity is covered.
        try {
            $price->amountFor(1);
        } catch (MalformedTierSet $e) {
            throw CatalogAuthoringException::malformed($e->getMessage());
        }
    }
}
