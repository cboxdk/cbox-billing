<?php

declare(strict_types=1);

namespace App\Billing\Wallet;

use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use Cbox\Billing\Wallet\Contracts\ExpiryPolicy;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\GrantScheduler;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Duration;
use Cbox\Billing\Wallet\ValueObjects\EndOfPeriod;
use Cbox\Billing\Wallet\ValueObjects\Fixed;
use Cbox\Billing\Wallet\ValueObjects\NeverExpires;
use Cbox\Billing\Wallet\ValueObjects\PlanGrant;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use DateTimeImmutable;

/**
 * Projects a plan into the wallet grants an org holds while subscribed to it (ADR-0013):
 * the two shapes are now ONE unified pool-grant model expanded by the engine's
 * {@see GrantScheduler}.
 *
 *  1. Each {@see PlanCreditGrant} definition (the plan's authored `{pool, kind, cadence,
 *     amount}` credits, e.g. the recurring `included` credit allotment) becomes a
 *     {@see PlanGrant}, sized per-seat when its kind is {@see GrantKind::PerSeat}.
 *  2. Each enabled, capped {@see PlanEntitlement} becomes a meter-denominated
 *     `included`-pool grant via {@see PlanGrant::includedAllowance()} — the ADR-0013 home
 *     of a meter's included allowance, so the {@see WalletIncludedAllowanceResolver} can
 *     source the exempt size from a real wallet balance rather than a hand-authored
 *     scalar. Unlimited or disabled dimensions grant nothing (no cap to fund).
 *
 * Every lot's id is deterministic in `(org, plan, pool|meter, slice boundary)`, and the
 * durable wallet's grant is idempotent on that id, so re-provisioning (a seeder re-run,
 * a re-subscribe) deposits each vested slice at most once.
 */
readonly class WalletProvisioner
{
    public function __construct(
        private Wallet $wallet,
        private GrantScheduler $scheduler = new GrantScheduler,
    ) {}

    /**
     * Deposit every grant `$plan` vests for `$org` over `[$periodStart, $periodEnd)` that
     * has vested by `$now`. Idempotent: an already-granted slice is a no-op.
     */
    public function provision(
        string $org,
        Plan $plan,
        int $seats,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $now,
    ): void {
        foreach ($this->planGrants($org, $plan, $seats) as $grant) {
            foreach ($this->scheduler->due($grant, $periodStart, $periodEnd, $now) as $lot) {
                $this->wallet->grant($lot);
            }
        }
    }

    /**
     * The plan's grants for `$org`: the authored credit-pool grants and the per-meter
     * included allowances, as one unified list of {@see PlanGrant}s.
     *
     * @return list<PlanGrant>
     */
    private function planGrants(string $org, Plan $plan, int $seats): array
    {
        $grants = [];

        foreach ($plan->creditGrants as $definition) {
            $amount = $this->sizedAmount($definition, $seats);

            if ($amount <= 0) {
                continue;
            }

            $pool = $this->pool($definition->pool);

            $grants[] = new PlanGrant(
                id: sprintf('%s:%s:credit:%s:s%d', $org, $plan->key, $definition->pool, $seats),
                org: $org,
                pool: $pool,
                denomination: $this->denomination($definition->denomination),
                amount: $definition->amount_mode->amount($amount, $definition->cadence),
                expiry: $this->expiryFor($pool, $definition->cadence, $definition->rollover_seconds),
                kind: $definition->kind,
            );
        }

        foreach ($plan->entitlements as $entitlement) {
            $grant = $this->includedAllowanceGrant($org, $plan->key, $seats, $entitlement);

            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    /**
     * A capped, enabled meter's included allowance as a meter-denominated `included`-pool
     * grant (ADR-0013). An unlimited or disabled dimension has no cap to fund, so it grants
     * nothing — deny-by-default is preserved by the base resolver, not a phantom allowance.
     */
    private function includedAllowanceGrant(string $org, string $planKey, int $seats, PlanEntitlement $entitlement): ?PlanGrant
    {
        $meter = $entitlement->meter?->key;

        if ($meter === null || ! $entitlement->enabled || $entitlement->unlimited || $entitlement->allowance <= 0) {
            return null;
        }

        return PlanGrant::includedAllowance(
            id: sprintf('%s:%s:included:%s:s%d', $org, $planKey, $meter, $seats),
            org: $org,
            meter: $meter,
            amount: new Fixed($entitlement->allowance, GrantCadence::Monthly),
            expiry: new EndOfPeriod,
        );
    }

    /** A per-seat grant scales with the subscription's seats; a base grant is flat. */
    private function sizedAmount(PlanCreditGrant $definition, int $seats): int
    {
        return $definition->kind === GrantKind::PerSeat
            ? $definition->amount * max(1, $seats)
            : $definition->amount;
    }

    /**
     * The expiry policy for a credit-pool grant (ADR-0013): a grant that opts into rollover
     * carries a {@see Duration} — each lot lives a fixed span and unused credit accumulates
     * across periods; otherwise a recurring cadence (or a pool that must carry an expiry)
     * resets each period ({@see EndOfPeriod}, use-it-or-lose-it) and everything else never
     * expires. Rollover is expiry-beyond-the-period, so it composes with any pool.
     */
    private function expiryFor(Pool $pool, GrantCadence $cadence, ?int $rolloverSeconds): ExpiryPolicy
    {
        if ($rolloverSeconds !== null && $rolloverSeconds > 0) {
            return new Duration($rolloverSeconds);
        }

        return $cadence->isRecurring() || $pool->requiresExpiry
            ? new EndOfPeriod
            : new NeverExpires;
    }

    /** Resolve the engine {@see Pool} behaviour matrix for a catalog pool key. */
    private function pool(string $key): Pool
    {
        return match ($key) {
            Pools::PROMOTIONAL => Pools::promotional(),
            Pools::PURCHASED => Pools::purchased(),
            Pools::REGULATED => Pools::regulated(),
            default => Pools::included(),
        };
    }

    /**
     * A three-letter upper-case code is an ISO money denomination; anything else (e.g.
     * `credit`) is a meter/unit denomination.
     */
    private function denomination(string $code): Denomination
    {
        return preg_match('/^[A-Z]{3}$/', $code) === 1
            ? Denomination::money($code)
            : Denomination::unit($code);
    }
}
