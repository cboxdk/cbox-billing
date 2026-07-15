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
 * @property int $amount_minor
 */
class InvoiceLine extends Model
{
    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_minor', 'amount_minor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_minor' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
