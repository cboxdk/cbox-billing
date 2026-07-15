<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A legal invoice issued to an organization by a seller of record. Totals are integer
 * minor units of `currency`, exposed as engine {@see Money}. Status moves
 * draft → open → paid (or void); {@see markPaid()} is the effect the payment seam runs
 * when a settled webhook is applied.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $seller
 * @property string $number
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property string $status
 * @property Carbon|null $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property string|null $gateway_reference
 */
class Invoice extends Model
{
    protected $fillable = [
        'organization_id', 'seller', 'number', 'currency',
        'subtotal_minor', 'tax_minor', 'total_minor',
        'status', 'issued_at', 'due_at', 'paid_at', 'gateway_reference',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** The invoice total as an engine money value object. */
    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    /** Whether this invoice has been settled. */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Stamp the invoice paid — the durable effect behind the payment applier seam.
     * Idempotent at the model layer: a re-apply on an already-paid invoice is a no-op,
     * so a redelivered settlement never rewrites the settlement record.
     */
    public function markPaid(Money $amount, ?string $gatewayReference): void
    {
        if ($this->isPaid()) {
            return;
        }

        $this->forceFill([
            'status' => 'paid',
            'paid_at' => now(),
            'gateway_reference' => $gatewayReference,
        ])->save();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
