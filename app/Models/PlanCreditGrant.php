<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use App\Billing\Wallet\Enums\AmountMode;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's credit-grant definition: the (pool, kind, cadence, amount, amount_mode) tuple
 * the engine's wallet issues on provisioning. `kind`, `cadence` and `amount_mode` are cast
 * to the engine/catalog enums so the catalog speaks the wallet's vocabulary directly:
 * `amount_mode` picks whether `amount` is granted whole at each cadence boundary
 * (`Fixed`) or distributed as a period total across the cadence slices (`Distributed`).
 *
 * @property int $id
 * @property int $plan_id
 * @property string $pool
 * @property GrantKind $kind
 * @property GrantCadence $cadence
 * @property int $amount
 * @property AmountMode $amount_mode
 * @property int|null $rollover_seconds
 * @property string $denomination
 */
class PlanCreditGrant extends Model
{
    use BelongsToEnvironment;

    protected $fillable = ['plan_id', 'pool', 'kind', 'cadence', 'amount', 'amount_mode', 'rollover_seconds', 'denomination'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => GrantKind::class,
            'cadence' => GrantCadence::class,
            'amount' => 'integer',
            'amount_mode' => AmountMode::class,
            'rollover_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
