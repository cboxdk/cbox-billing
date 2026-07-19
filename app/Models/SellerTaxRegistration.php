<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax registration (a VAT/GST number or permit) a selling entity holds in a jurisdiction.
 * Console-authored, per the tax package's design: the app authors the seller's REGISTRATIONS
 * (nexus), never the rate numbers — those come from the cited rate-source feeds.
 *
 * @property int $id
 * @property string $seller_entity_id
 * @property string $country
 * @property string $number
 * @property string|null $subdivision
 * @property string|null $scheme
 */
class SellerTaxRegistration extends Model
{
    protected $fillable = ['seller_entity_id', 'country', 'number', 'subdivision', 'scheme'];

    /** @return BelongsTo<SellerEntity, $this> */
    public function sellerEntity(): BelongsTo
    {
        return $this->belongsTo(SellerEntity::class);
    }
}
