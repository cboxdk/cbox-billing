<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a credit note — the reversed portion of an invoice line. Amounts are
 * POSITIVE magnitudes in minor units of the parent credit note's currency.
 *
 * @property int $id
 * @property int $credit_note_id
 * @property string $description
 * @property int $quantity
 * @property int $net_minor
 * @property int $tax_minor
 * @property int $gross_minor
 */
class CreditNoteLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['credit_note_id', 'description', 'quantity', 'net_minor', 'tax_minor', 'gross_minor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'gross_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }
}
