<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Cpq\Enums\QuoteDiscountKind;
use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\QuoteCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a {@see Quote}: a catalog plan line (a {@see Plan} priced at `quantity` units in the
 * quote currency, through the engine tier calculator) or a custom one-off (a free-text
 * `description` at `unit_amount_minor` per unit). An optional per-line discount reduces the line
 * net before tax. The amounts here are pricing INPUTS — the tax-aware net/tax/gross is always
 * computed through the engine at render time by {@see QuoteCalculator}.
 *
 * @property int $id
 * @property int $quote_id
 * @property int $sort_order
 * @property QuoteLineType $type
 * @property int|null $plan_id
 * @property string|null $description
 * @property int $quantity
 * @property int|null $unit_amount_minor
 * @property QuoteDiscountKind|null $discount_kind
 * @property int|null $discount_value
 * @property bool $recurring
 */
class QuoteLine extends Model
{
    protected $fillable = [
        'quote_id', 'sort_order', 'type', 'plan_id', 'description', 'quantity',
        'unit_amount_minor', 'discount_kind', 'discount_value', 'recurring',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'type' => QuoteLineType::class,
            'plan_id' => 'integer',
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'discount_kind' => QuoteDiscountKind::class,
            'discount_value' => 'integer',
            'recurring' => 'boolean',
        ];
    }

    /** The label shown for this line: the plan name for a plan line, else its custom description. */
    public function label(): string
    {
        if ($this->type === QuoteLineType::Plan) {
            return $this->plan instanceof Plan ? $this->plan->name : 'Plan';
        }

        return $this->description !== null && $this->description !== '' ? $this->description : 'Custom line';
    }

    /** Whether this line carries a discount. */
    public function hasDiscount(): bool
    {
        return $this->discount_kind !== null && $this->discount_value !== null && $this->discount_value > 0;
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
