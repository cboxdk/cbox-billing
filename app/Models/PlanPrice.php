<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Catalog\Pricing\TierCalculator;
use Cbox\Billing\Catalog\ValueObjects\Price;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;
use Cbox\Billing\Money\Money;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One currency's recurring price for a plan — integer minor units plus an ISO
 * currency, exposed as an engine {@see Money}. A plan has one row per currency it is
 * priced in; the account's billing currency selects which applies.
 *
 * `pricing_model` is how the price turns a quantity into an amount (engine
 * {@see PricingModel}): `flat` is the plain recurring amount, the tiered models carry a
 * `plan_price_tiers` bracket set (and `package` a `package_size`). {@see toPrice()}
 * projects the row into the engine {@see Price} value object so the same
 * {@see TierCalculator} the engine bills with computes any
 * quantity preview shown in the console.
 *
 * @property int $id
 * @property int $plan_id
 * @property string $currency
 * @property int $price_minor
 * @property string $pricing_model
 * @property int|null $package_size
 */
class PlanPrice extends Model
{
    use BelongsToEnvironment;

    protected $fillable = ['plan_id', 'currency', 'price_minor', 'pricing_model', 'package_size'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'package_size' => 'integer',
        ];
    }

    /** This price as an engine money value object. */
    public function money(): Money
    {
        return Money::ofMinor($this->price_minor, $this->currency);
    }

    /** The pricing model this price charges under. Falls back to {@see PricingModel::Flat}. */
    public function model(): PricingModel
    {
        return PricingModel::tryFrom($this->pricing_model ?? 'flat') ?? PricingModel::Flat;
    }

    /**
     * Project this row into the engine's {@see Price} value object — the same shape the
     * engine's tier calculator prices from. The base `price_minor` is the unit amount for
     * `flat`/`per_unit`; the ordered {@see PlanPriceTier}s become the engine tier set.
     */
    public function toPrice(): Price
    {
        $currency = $this->currency;

        return new Price(
            id: (string) $this->id,
            productId: (string) $this->plan_id,
            model: $this->model(),
            unitAmount: $this->money(),
            effectiveFrom: new DateTimeImmutable('@0'),
            packageSize: $this->package_size,
            tiers: array_values($this->tiers
                ->sortBy('sort_order')
                ->map(static fn (PlanPriceTier $tier): PriceTier => $tier->toPriceTier($currency))
                ->all()),
        );
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<PlanPriceTier, $this> */
    public function tiers(): HasMany
    {
        return $this->hasMany(PlanPriceTier::class)->orderBy('sort_order');
    }
}
