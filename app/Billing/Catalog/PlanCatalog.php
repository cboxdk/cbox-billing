<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanPriceTier;
use Cbox\Billing\Catalog\Contracts\Catalog;
use Cbox\Billing\Catalog\Enums\PriceKind;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;
use Cbox\Billing\Catalog\ValueObjects\Product;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Money\Money;
use DateTimeImmutable;

/**
 * The database-backed engine {@see Catalog}: it projects this app's durable {@see Plan} /
 * {@see PlanPrice} rows into the engine's product + price value objects for one billing
 * currency, keyed by the plan **key** (the id the engine catalog uses — the same id
 * {@see Plan::toCatalogProduct()} projects), so a plan's sunset ({@see Product::$retirement})
 * and its per-currency price resolve through the one contract the retirement resolver and
 * renewal policy consume (ADR-0016).
 *
 * It is built per-currency ({@see for()}) because the engine {@see Price} carries a single
 * currency; the account's billing currency selects which price version a subscriber
 * grandfathers on. Prices are pinned from epoch (the app has one recurring version per
 * currency), so effective-date resolution always returns the current row.
 */
readonly class PlanCatalog implements Catalog
{
    /**
     * @param  array<string, Product>  $products  keyed by plan key
     * @param  array<string, Price>  $prices  keyed by plan key
     */
    private function __construct(
        private array $products,
        private array $prices,
    ) {}

    /** Build the catalog for `$currency` from every plan the app carries (offered + legacy). */
    public static function for(string $currency): self
    {
        $products = [];
        $prices = [];

        $plans = Plan::query()
            ->with(['product', 'prices.tiers', 'defaultSuccessor'])
            ->get();

        foreach ($plans as $plan) {
            $products[$plan->key] = $plan->toCatalogProduct();

            $price = $plan->prices->firstWhere('currency', $currency);

            if ($price instanceof PlanPrice) {
                $prices[$plan->key] = self::price($plan, $price);
            }
        }

        return new self($products, $prices);
    }

    public function product(string $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function products(): array
    {
        return array_values($this->products);
    }

    public function priceFor(string $productId, DateTimeImmutable $at): ?Price
    {
        $price = $this->prices[$productId] ?? null;

        return $price !== null && $price->isEffectiveAt($at) ? $price : null;
    }

    public function termPriceFor(string $productId, Term $term, PriceKind $kind, DateTimeImmutable $at): ?Price
    {
        // The app prices plans as rolling recurring products, not fixed-term (registrar)
        // price points, so there is no term-scoped price to resolve.
        return null;
    }

    public function priceQuantity(string $productId, int $quantity, DateTimeImmutable $at): ?Money
    {
        return $this->priceFor($productId, $at)?->amountFor($quantity);
    }

    /** Project one plan's per-currency price row into the engine {@see Price}, keyed by plan key. */
    private static function price(Plan $plan, PlanPrice $row): Price
    {
        $currency = $row->currency;

        return new Price(
            id: (string) $row->id,
            productId: $plan->key,
            model: $row->model(),
            unitAmount: $row->money(),
            effectiveFrom: new DateTimeImmutable('@0'),
            packageSize: $row->package_size,
            tiers: array_values($row->tiers
                ->sortBy('sort_order')
                ->map(static fn (PlanPriceTier $tier): PriceTier => $tier->toPriceTier($currency))
                ->all()),
        );
    }
}
