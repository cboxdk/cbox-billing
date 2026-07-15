<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 */
class Plan extends Model
{
    protected $fillable = [
        'product_id', 'key', 'name', 'interval', 'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
}
