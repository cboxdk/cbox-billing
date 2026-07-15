<?php

declare(strict_types=1);

namespace App\Billing\Enforcement;

use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\LeasedEnforcement;
use Cbox\Billing\Metering\ValueObjects\AllowanceLease;
use Illuminate\Database\ConnectionInterface;

/**
 * The central budget the enforcement API leases slices of — billing's side of the
 * pessimistic-lease contract the SDK's local {@see LeasedEnforcement}
 * refills from. Outstanding leases are held per `(org, meter)` in `allowance_leases`.
 *
 * Remaining includable allowance is `policy.allowance − outstanding`, so the sum of
 * outstanding leases can NEVER exceed the allowance — a node can only ever over-reject,
 * never over-grant (the hard-limit invariant). Deny-by-default: an unknown or disabled
 * meter leases zero. A `Bill`-overage or unlimited meter leases the requested size (its
 * spend cap is the wallet/credit balance, reconciled from the durable ledger — not this
 * allowance faucet).
 */
readonly class CentralAllowanceLeaseSource implements AllowanceLeaseSource
{
    private const TABLE = 'allowance_leases';

    public function __construct(
        private ConnectionInterface $db,
        private MeterPolicyResolver $policies,
    ) {}

    public function lease(string $org, string $meter, int $want): AllowanceLease
    {
        if ($want <= 0) {
            return new AllowanceLease($org, $meter, 0);
        }

        $policy = $this->policies->resolve($org, $meter);

        // Deny-by-default: nothing entitled, nothing leased.
        if ($policy === null || ! $policy->enabled) {
            return new AllowanceLease($org, $meter, 0);
        }

        return $this->db->transaction(function () use ($org, $meter, $want, $policy): AllowanceLease {
            $outstanding = $this->outstanding($org, $meter, lock: true);

            // Unlimited or metered overage bills against the leased paid budget — the
            // includable allowance is not the cap, so grant what was asked.
            if ($policy->unlimited || $policy->overage === OverageBehaviour::Bill) {
                $granted = $want;
            } else {
                // Hard block at the isolated allowance boundary.
                $remaining = max(0, $policy->allowance - $outstanding);
                $granted = max(0, min($want, $remaining));
            }

            if ($granted > 0) {
                $this->store($org, $meter, $outstanding + $granted);
            }

            return new AllowanceLease($org, $meter, $granted);
        });
    }

    public function giveBack(string $org, string $meter, int $unused): void
    {
        if ($unused <= 0) {
            return;
        }

        $this->db->transaction(function () use ($org, $meter, $unused): void {
            $outstanding = $this->outstanding($org, $meter, lock: true);
            $this->store($org, $meter, max(0, $outstanding - $unused));
        });
    }

    /** Units currently leased out (held by nodes) for `(org, meter)`. */
    public function outstandingFor(string $org, string $meter): int
    {
        return $this->outstanding($org, $meter);
    }

    private function outstanding(string $org, string $meter, bool $lock = false): int
    {
        $query = $this->db->table(self::TABLE)->where('org', $org)->where('meter', $meter);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row !== null && is_numeric($row->outstanding) ? (int) $row->outstanding : 0;
    }

    private function store(string $org, string $meter, int $outstanding): void
    {
        $this->db->table(self::TABLE)->updateOrInsert(
            ['org' => $org, 'meter' => $meter],
            ['outstanding' => $outstanding, 'updated_at' => $this->db->raw('CURRENT_TIMESTAMP')],
        );
    }
}
