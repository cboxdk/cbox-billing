<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One feature row of a {@see PricingTable}'s comparison matrix — which catalog {@see Feature}
 * to compare across the table's plan columns, and in what order. The cell for each column is
 * read from that column plan's {@see PlanFeature} grant (✓ / ✗ / typed config value); this row
 * only selects and orders the feature, it stores no per-plan answer.
 *
 * @property int $id
 * @property int $pricing_table_id
 * @property int $feature_id
 * @property int $sort_order
 */
class PricingTableFeature extends Model
{
    use BelongsToEnvironment;

    protected $fillable = ['pricing_table_id', 'feature_id', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return BelongsTo<PricingTable, $this> */
    public function pricingTable(): BelongsTo
    {
        return $this->belongsTo(PricingTable::class);
    }

    /** @return BelongsTo<Feature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
