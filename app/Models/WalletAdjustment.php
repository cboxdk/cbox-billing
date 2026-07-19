<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator wallet adjustment (Wave 3): the immutable audit record of a manual credit
 * grant or debit an operator made through the console, written alongside the engine
 * wallet movement. `amount` is signed minor/credit units — positive for a grant, negative
 * for a debit — and never a balance (the balance is always derived from the wallet lots).
 *
 * @property int $id
 * @property string $organization_id
 * @property string $pool_key
 * @property string $denomination_code
 * @property bool $denomination_is_money
 * @property int $amount
 * @property string $direction
 * @property string $reason
 * @property string|null $actor
 * @property string $grant_id
 */
class WalletAdjustment extends Model
{
    public const DIRECTION_GRANT = 'grant';

    public const DIRECTION_DEBIT = 'debit';

    protected $fillable = [
        'organization_id', 'pool_key', 'denomination_code', 'denomination_is_money',
        'amount', 'direction', 'reason', 'actor', 'grant_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'denomination_is_money' => 'boolean',
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
