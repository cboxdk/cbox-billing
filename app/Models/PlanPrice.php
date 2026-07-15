<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One currency's recurring price for a plan — integer minor units plus an ISO
 * currency, exposed as an engine {@see Money}. A plan has one row per currency it is
 * priced in; the account's billing currency selects which applies.
 *
 * @property int $id
 * @property int $plan_id
 * @property string $currency
 * @property int $price_minor
 */
class PlanPrice extends Model
{
    protected $fillable = ['plan_id', 'currency', 'price_minor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
        ];
    }

    /** This price as an engine money value object. */
    public function money(): Money
    {
        return Money::ofMinor($this->price_minor, $this->currency);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
