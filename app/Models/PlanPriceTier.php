<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Cbox\Billing\Catalog\ValueObjects\PriceTier;
use Cbox\Billing\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bracket of a tiered {@see PlanPrice}, projected into the engine's
 * {@see PriceTier} value object. `up_to` is the inclusive upper bound in units (null for
 * the final unbounded tier); `unit_minor` is the per-unit rate within the tier and
 * `flat_minor` an optional flat amount whose meaning is set by the price's pricing model.
 *
 * @property int $id
 * @property int $plan_price_id
 * @property int|null $up_to
 * @property int $unit_minor
 * @property int|null $flat_minor
 * @property int $sort_order
 */
class PlanPriceTier extends Model
{
    use BelongsToEnvironment;

    protected $fillable = ['plan_price_id', 'up_to', 'unit_minor', 'flat_minor', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'up_to' => 'integer',
            'unit_minor' => 'integer',
            'flat_minor' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** Project this row into the engine's {@see PriceTier} in `$currency`. */
    public function toPriceTier(string $currency): PriceTier
    {
        return new PriceTier(
            upTo: $this->up_to,
            unitAmount: Money::ofMinor($this->unit_minor, $currency),
            flatAmount: $this->flat_minor !== null ? Money::ofMinor($this->flat_minor, $currency) : null,
        );
    }

    /** @return BelongsTo<PlanPrice, $this> */
    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }
}
