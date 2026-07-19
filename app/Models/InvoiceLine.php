<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice. `unit_minor` is the per-unit price and `amount_minor` the
 * extended total, both integer minor units of the parent invoice's currency.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_minor
 * @property int|null $net_minor
 * @property int $amount_minor
 * @property string|null $tax_treatment
 * @property string|null $tax_note
 * @property string|null $tax_rate
 */
class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'description', 'quantity', 'unit_minor', 'net_minor', 'amount_minor',
        'tax_treatment', 'tax_note', 'tax_rate',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_minor' => 'integer',
            'net_minor' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
