<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
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
 * @property int|null $subscription_id
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
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
 * @property int|null $exemption_certificate_id
 * @property string|null $exemption_reason
 */
class Invoice extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'organization_id', 'subscription_id', 'period_start', 'period_end',
        'seller', 'number', 'currency',
        'subtotal_minor', 'tax_minor', 'total_minor',
        'status', 'issued_at', 'due_at', 'paid_at', 'gateway_reference',
        'exemption_certificate_id', 'exemption_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
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

    /** Whether this invoice was zero-rated by a customer exemption certificate. */
    public function isTaxExempt(): bool
    {
        return $this->exemption_certificate_id !== null;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<TaxExemptionCertificate, $this> */
    public function exemptionCertificate(): BelongsTo
    {
        return $this->belongsTo(TaxExemptionCertificate::class, 'exemption_certificate_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
