<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use App\Billing\Nexus\InvoiceSalesLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Operator-declared sales into a US state through a channel OTHER than this platform
 * (a marketplace, a second storefront, another billing system), per state and calendar
 * year. Economic nexus turns on a seller's TOTAL sales into a state, so these are added
 * to this platform's invoiced sales in the {@see InvoiceSalesLedger}.
 * Whole US dollars — a channel's own figures are reconciled to USD before entry.
 *
 * @property int $id
 * @property string $environment
 * @property string $seller_entity_id
 * @property string $subdivision
 * @property int $period_year
 * @property int $sales_dollars
 * @property int $transactions
 * @property string|null $source
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerExternalSales extends Model
{
    use BelongsToEnvironment;

    protected $table = 'seller_external_sales';

    protected $fillable = ['seller_entity_id', 'subdivision', 'period_year', 'sales_dollars', 'transactions', 'source', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'sales_dollars' => 'integer',
            'transactions' => 'integer',
        ];
    }

    /** @return BelongsTo<SellerEntity, $this> */
    public function sellerEntity(): BelongsTo
    {
        return $this->belongsTo(SellerEntity::class);
    }
}
