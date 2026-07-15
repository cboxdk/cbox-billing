<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price point of a product. The recurring price is integer minor units plus an ISO
 * currency, exposed as an engine {@see Money}. Credit grants and metered entitlements
 * are the child collections the host projects into wallet grants and meter policies.
 *
 * @property int $id
 * @property int $product_id
 * @property string $key
 * @property string $name
 * @property int $price_minor
 * @property string $currency
 * @property string $interval
 * @property bool $active
 */
class Plan extends Model
{
    protected $fillable = [
        'product_id', 'key', 'name', 'price_minor', 'currency', 'interval', 'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** The recurring price as an engine money value object. */
    public function price(): Money
    {
        return Money::ofMinor($this->price_minor, $this->currency);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
