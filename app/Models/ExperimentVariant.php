<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One arm of an {@see Experiment}: a label, whether it is the required control (the baseline),
 * a non-negative integer traffic `weight` (relative, not a percentage — a visitor is bucketed
 * in proportion to the weights), and the artifact it serves. The served artifact is a
 * {@see PricingTable} pointer (`served_pricing_table_id`); a null pointer means "serve the
 * experiment's own base table" — the natural default for the control, which shows the
 * unchanged page.
 *
 * The variant carries no pricing of its own — it points at a real, catalog-backed pricing
 * table, so what a visitor sees under the experiment is exactly what that table renders.
 *
 * @property int $id
 * @property int $experiment_id
 * @property string $label
 * @property bool $is_control
 * @property int $weight
 * @property int $sort_order
 * @property int|null $served_pricing_table_id
 */
class ExperimentVariant extends Model
{
    use BelongsToEnvironment;

    protected $fillable = [
        'experiment_id', 'label', 'is_control', 'weight', 'sort_order', 'served_pricing_table_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_control' => 'boolean',
            'weight' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Experiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    /** The pricing table this variant serves, or null to serve the experiment's base table. */
    /** @return BelongsTo<PricingTable, $this> */
    public function servedTable(): BelongsTo
    {
        return $this->belongsTo(PricingTable::class, 'served_pricing_table_id');
    }
}
