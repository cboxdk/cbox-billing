<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One plan column of a {@see PricingTable}. It names the (monthly) plan the column shows, its
 * display order, whether it is the featured/highlighted column (+ an optional badge and a
 * one-line highlight), and — for the monthly/yearly toggle — an optional `annual_plan_id`, the
 * yearly-priced sibling plan the toggle swaps the column's price to. The column carries no
 * pricing of its own: the amounts, entitlements and feature grants are read from the plan(s).
 *
 * @property int $id
 * @property int $pricing_table_id
 * @property int $plan_id
 * @property int|null $annual_plan_id
 * @property int $sort_order
 * @property bool $featured
 * @property string|null $badge
 * @property string|null $highlight
 */
class PricingTablePlan extends Model
{
    protected $fillable = [
        'pricing_table_id', 'plan_id', 'annual_plan_id', 'sort_order', 'featured', 'badge', 'highlight',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'featured' => 'boolean',
        ];
    }

    /** @return BelongsTo<PricingTable, $this> */
    public function pricingTable(): BelongsTo
    {
        return $this->belongsTo(PricingTable::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** The yearly-priced sibling plan the interval toggle swaps to, if the column carries one. */
    /** @return BelongsTo<Plan, $this> */
    public function annualPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'annual_plan_id');
    }
}
