<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Catalog\Enums\PlanStatus;
use Cbox\Billing\Catalog\ValueObjects\PlanRetirement;
use Cbox\Billing\Catalog\ValueObjects\Product as CatalogProduct;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A price point of a product. A plan can be priced in several ISO currencies — its
 * recurring amounts live one-per-currency in {@see PlanPrice}, and the account's
 * billing currency selects which applies via {@see priceFor()}. Credit grants and
 * metered entitlements are the child collections the host projects into wallet grants
 * and meter policies.
 *
 * @property int $id
 * @property int $product_id
 * @property string $key
 * @property string $name
 * @property string $interval
 * @property bool $active
 * @property Carbon|null $retires_at
 * @property int|null $default_successor_plan_id
 */
class Plan extends Model
{
    protected $fillable = [
        'product_id', 'key', 'name', 'interval', 'active',
        'retires_at', 'default_successor_plan_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'retires_at' => 'datetime',
        ];
    }

    /**
     * The recurring price in `$currency` as an engine money value object. Deny-by-default:
     * a currency the plan is not priced in is refused rather than assigned a fabricated
     * rate — the caller must offer the account a currency the plan actually carries.
     */
    public function priceFor(string $currency): Money
    {
        $price = $this->prices->firstWhere('currency', $currency);

        if (! $price instanceof PlanPrice) {
            throw new RuntimeException(sprintf(
                'Plan [%s] is not priced in %s (available: %s).',
                $this->key,
                $currency,
                implode(', ', $this->pricedCurrencies()) ?: 'none',
            ));
        }

        return $price->money();
    }

    /**
     * The ISO currencies this plan is priced in.
     *
     * @return list<string>
     */
    public function pricedCurrencies(): array
    {
        $currencies = [];

        foreach ($this->prices as $price) {
            $currencies[] = $price->currency;
        }

        return $currencies;
    }

    /**
     * Project this plan into the engine's catalog {@see CatalogProduct} — the shape the
     * {@see TransitionPolicy} and plan-change
     * previewer gate on (ADR-0010). Its **family** is the owning product's key, so every
     * plan under one product is a single family a subscription may move within freely;
     * an inactive plan is `Legacy` — a valid transition source but never a target.
     */
    public function toCatalogProduct(): CatalogProduct
    {
        return new CatalogProduct(
            id: $this->key,
            name: $this->name,
            family: $this->family(),
            status: $this->active ? PlanStatus::Offered : PlanStatus::Legacy,
            retirement: $this->retirement(),
        );
    }

    /**
     * The plan's sunset projected into the engine {@see PlanRetirement} value object, or
     * null when the plan is not being retired. The default successor is carried as the
     * successor plan's KEY — the id the engine catalog keys products by (ADR-0016), never
     * the durable numeric id — so the engine resolver can look it up as a catalog product.
     */
    public function retirement(): ?PlanRetirement
    {
        if ($this->retires_at === null) {
            return null;
        }

        return new PlanRetirement(
            retiresAt: $this->retires_at->toDateTimeImmutable(),
            defaultSuccessorPlanId: $this->defaultSuccessor?->key,
        );
    }

    /** Whether the plan carries a retirement cutoff (it is being sunset). */
    public function isRetiring(): bool
    {
        return $this->retires_at !== null;
    }

    /** Whether the plan's retirement cutoff has passed as of `$at` (default: now). */
    public function isRetiredAt(?DateTimeImmutable $at = null): bool
    {
        if ($this->retires_at === null) {
            return false;
        }

        $at ??= Carbon::now()->toDateTimeImmutable();

        return $at >= $this->retires_at->toDateTimeImmutable();
    }

    /** The transition family this plan belongs to: its owning product's key. */
    public function family(): string
    {
        $product = $this->product;

        return $product instanceof Product ? $product->key : $this->key;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The plan a subscriber falls to at renewal when this plan retires and they make no choice. */
    /** @return BelongsTo<Plan, $this> */
    public function defaultSuccessor(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'default_successor_plan_id');
    }

    /** @return HasMany<PlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /** @return HasMany<PlanCreditGrant, $this> */
    public function creditGrants(): HasMany
    {
        return $this->hasMany(PlanCreditGrant::class);
    }

    /** @return HasMany<PlanEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    /** Every subscription on this plan (serving or historical) — the subscriber picture. */
    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
