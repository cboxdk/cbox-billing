<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The persisted legal credit note (Wave 3): the reversal of (part of) an issued invoice,
 * carrying its OWN gapless number from the seller's credit-note sequence and referencing
 * the original invoice. Fed by the engine's {@see Cbox\Billing\Events\CreditNoteIssued}
 * event, so a refund or adjustment issued through the engine is the only thing that
 * creates one.
 *
 * Stored amounts are POSITIVE magnitudes in minor units of `currency`; the reversal sign
 * is the document's meaning (money returned to the customer).
 *
 * @property int $id
 * @property string $number
 * @property string $invoice_number
 * @property int|null $invoice_id
 * @property string $organization_id
 * @property string $seller
 * @property string $currency
 * @property int $net_minor
 * @property int $tax_minor
 * @property int $gross_minor
 * @property string $reason
 * @property string $kind
 * @property Carbon $issued_at
 */
class CreditNote extends Model
{
    protected $fillable = [
        'number', 'invoice_number', 'invoice_id', 'organization_id', 'seller',
        'currency', 'net_minor', 'tax_minor', 'gross_minor', 'reason', 'kind',
        'issued_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'gross_minor' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    /** The credited gross as an engine money value object (positive magnitude). */
    public function gross(): Money
    {
        return Money::ofMinor($this->gross_minor, $this->currency);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }
}
