<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's credit-grant definition: the (pool, kind, cadence, amount) tuple the
 * engine's wallet issues on provisioning. `kind` and `cadence` are cast to the
 * engine's own enums so the catalog speaks the wallet's vocabulary directly.
 *
 * @property int $id
 * @property int $plan_id
 * @property string $pool
 * @property GrantKind $kind
 * @property GrantCadence $cadence
 * @property int $amount
 * @property string $denomination
 */
class PlanCreditGrant extends Model
{
    protected $fillable = ['plan_id', 'pool', 'kind', 'cadence', 'amount', 'denomination'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => GrantKind::class,
            'cadence' => GrantCadence::class,
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
