<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Billing\Wallet\Enums\AmountMode;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;

/**
 * Create / edit / delete a plan's recurring or one-time {@see PlanCreditGrant} — the
 * `(pool, kind, cadence, amount, amount_mode)` tuple the engine's wallet issues on
 * provisioning and vests each cadence boundary. The grant math is idempotent and
 * time-keyed, so editing or removing a grant only changes what vests from the next
 * boundary on; already-vested credits stand. A plan can carry several grants (different
 * pools/kinds/cadences), so each is authored on its own row rather than upserted by pool.
 */
readonly class PlanCreditGrantAuthoring
{
    /**
     * @param  array{pool: string, kind: GrantKind, cadence: GrantCadence, amount: int, amount_mode: AmountMode, rollover_seconds: ?int, denomination: string}  $data
     */
    public function create(Plan $plan, array $data): PlanCreditGrant
    {
        return $plan->creditGrants()->create($this->attributes($data));
    }

    /**
     * @param  array{pool: string, kind: GrantKind, cadence: GrantCadence, amount: int, amount_mode: AmountMode, rollover_seconds: ?int, denomination: string}  $data
     */
    public function update(PlanCreditGrant $grant, array $data): PlanCreditGrant
    {
        $grant->update($this->attributes($data));

        return $grant;
    }

    /** Remove the grant — nothing further vests from it; already-vested credits stand. */
    public function delete(PlanCreditGrant $grant): void
    {
        $grant->delete();
    }

    /**
     * @param  array{pool: string, kind: GrantKind, cadence: GrantCadence, amount: int, amount_mode: AmountMode, rollover_seconds: ?int, denomination: string}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'pool' => $data['pool'],
            'kind' => $data['kind'],
            'cadence' => $data['cadence'],
            'amount' => $data['amount'],
            'amount_mode' => $data['amount_mode'],
            // A rollover window only means anything for a recurring grant; a one-time grant
            // (Once) never rolls a period so it carries no rollover.
            'rollover_seconds' => $data['cadence'] === GrantCadence::Once ? null : $data['rollover_seconds'],
            'denomination' => $data['denomination'],
        ];
    }
}
