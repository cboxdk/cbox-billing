<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The stored mapping from a billing organization to its customer handle at a payment
 * gateway (ADR-0009 Path B). The gateway owns the card vault and keys everything —
 * PaymentIntents, SetupIntents, saved methods — by ITS customer id (`cus_…` for Stripe),
 * never by the raw org id; this row is where that id lives so it is minted once and
 * reused across every subsequent intent for the same `(organization_id, gateway)`.
 *
 * @property int $id
 * @property string $organization_id
 * @property bool $livemode
 * @property string $gateway
 * @property string $gateway_customer_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GatewayCustomer extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'organization_id', 'gateway', 'gateway_customer_id',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
