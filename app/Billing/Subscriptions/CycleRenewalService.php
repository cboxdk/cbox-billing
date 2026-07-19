<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Reporting\SubscriptionMrrMovementRecorder;
use App\Billing\Subscriptions\ValueObjects\RenewalOutcome;
use App\Billing\Wallet\WalletProvisioner;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\Enums\CreditGrantMode;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\GrantScheduler;
use Cbox\Billing\Wallet\Support\CycleGrants;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The scheduled cycle renewal (ADR-0012/0013/0014): on each subscription's billing-cycle
 * boundary it fires the recurring per-cycle credit allotments, advances the period, renews
 * add-ons, and issues the renewal invoice. It is the recurring counterpart to the initial
 * subscribe — and it reuses the very same seams, so a renewal and a first provision grant
 * identical lots.
 *
 * The single load-bearing idea is that recurring granting is TIME-KEYED and idempotent
 * (ADR-0002): every run projects the plan's grants over the current period and deposits
 * only the slices that have vested by `now` and are not already in the wallet
 * ({@see WalletProvisioner} → {@see GrantScheduler} →
 * {@see CycleGrants}). So the daily run:
 *
 *  - grants a finer-cadence allotment (a monthly reset inside a yearly period, a daily
 *    drip, a `Distributed` monthly slice of a yearly total) exactly as it comes due, never
 *    twice;
 *  - is safe to re-run inside a cycle — a second run grants nothing new and, because the
 *    period only advances once the boundary is passed, issues no duplicate invoice.
 *
 * The expiry policy the lots were granted with (ADR-0013) turns each period over on its
 * own: an `EndOfPeriod` lot dies at the cadence boundary — swept here so the allowance
 * RESETS — while a `Duration` lot outlives the boundary and ROLLS OVER, accumulating.
 *
 * Paused and canceled subscriptions are skipped (deny-by-default); a subscription whose
 * end-of-period cancellation has come due is ENDED here rather than renewed.
 */
readonly class CycleRenewalService
{
    public function __construct(
        private ConnectionInterface $db,
        private Wallet $wallet,
        private WalletProvisioner $provisioner,
        private GeneratesInvoices $invoices,
        private SubscriptionMrrMovementRecorder $movements,
    ) {}

    /**
     * Run the renewal for one subscription at `$now` (default: the current time, so a
     * time-travelled test drives it through `Carbon::setTestNow`).
     */
    public function renew(Subscription $subscription, ?DateTimeImmutable $now = null): RenewalOutcome
    {
        $now ??= Carbon::now()->toDateTimeImmutable();

        // Deny-by-default: a paused or canceled subscription neither grants nor renews.
        if ($subscription->isPaused() || $subscription->status === SubscriptionStatus::Canceled) {
            return RenewalOutcome::skipped();
        }

        $plan = $this->planOf($subscription);
        $subscription->loadMissing(['organization', 'addOns']);

        $org = $subscription->organization_id;
        $nowMs = $this->toMillis($now);

        // Contributing MRR before any state change, captured for a churn movement if a due
        // cancellation lands (recorded after the transaction commits).
        $previousMrr = $this->movements->contributing($subscription);

        // Everything that reads the period and mutates state runs UNDER a row lock (H5): two
        // concurrent RenewSubscriptionJobs serialize on the claim, and the loser re-reads the
        // now-advanced period and finds nothing due — so the period advance is exactly-once,
        // the durable backstop being the (subscription, period) unique invoice guard (H4).
        $outcome = $this->db->transaction(function () use ($subscription, $plan, $org, $now, $nowMs): array {
            $this->claim($subscription);

            // Re-check deny-by-default against the freshly-read row.
            if ($subscription->isPaused() || $subscription->status === SubscriptionStatus::Canceled) {
                return ['kind' => 'skipped'];
            }

            $periodEnd = $subscription->current_period_end;
            $baseDue = $periodEnd !== null && $periodEnd->toDateTimeImmutable() <= $now;

            // A due end-of-period cancellation ends the subscription instead of renewing it:
            // forfeit the forfeitable allotment and stamp it canceled.
            if ($baseDue && $subscription->cancel_at_period_end) {
                $this->wallet->forfeit($org, $nowMs);
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Canceled,
                    'cancel_at_period_end' => false,
                    'canceled_at' => Carbon::now(),
                ])->save();

                return ['kind' => 'canceled'];
            }

            // Deny-by-default (ADR-0016): never renew — and so never charge — a subscription
            // still on a retired plan. Retiring plans' subscribers are migrated off by
            // `billing:migrate-retiring-plans` before renewal; any left on a past-cutoff plan
            // at its boundary is unresolved and must be surfaced, not billed on the retired plan.
            if ($baseDue && $plan->isRetiredAt($now)) {
                return ['kind' => 'skipped'];
            }

            // 1. Grant every recurring slice of the CURRENT period that has vested by now.
            //    Idempotent per slice, so a re-run inside the cycle deposits nothing new
            //    and a finer cadence drips exactly on its own boundary.
            $this->provision($subscription, $plan, $this->basePeriod($subscription), $now);

            // 2. Sweep every lot whose EndOfPeriod expiry has passed — the RESET half of
            //    the policy. A Duration (rollover) lot outlives its boundary and is left.
            $this->wallet->expire($org, $nowMs);

            // 3. Advance onto the next cycle once the boundary is passed: grant the new
            //    period's opening slices and mark it for invoicing.
            $basePeriod = $this->basePeriod($subscription);
            $baseRenewed = false;

            if ($baseDue) {
                $basePeriod = $this->advanceBase($subscription, $plan, $now);
                $baseRenewed = true;
            }

            // 4. Renew add-ons: an aligned add-on follows the (now-advanced) base period, an
            //    independent one its own cycle — each idempotent on its resolved boundary.
            $addOnsRenewed = $this->renewAddOns($subscription, $basePeriod, $now);

            return ['kind' => 'processed', 'baseRenewed' => $baseRenewed, 'addOnsRenewed' => $addOnsRenewed];
        });

        if ($outcome['kind'] === 'skipped') {
            return RenewalOutcome::skipped();
        }

        if ($outcome['kind'] === 'canceled') {
            // Churn recorded as the scheduled cancel lands: contributing MRR moves amount → 0.
            $this->movements->record($subscription, $previousMrr, $this->movements->contributing($subscription));

            return RenewalOutcome::canceled();
        }

        return RenewalOutcome::processed(
            $outcome['baseRenewed'],
            $outcome['addOnsRenewed'],
            $this->invoiceRenewal($subscription, $outcome['baseRenewed']),
        );
    }

    /**
     * Claim the subscription for this renewal with a `SELECT … FOR UPDATE`, then refresh the
     * in-memory row's period + status from the locked copy so every decision that follows is
     * made against the freshly-read state, not the caller's possibly-stale snapshot. Two
     * concurrent renewals serialize here; the loser sees the already-advanced period.
     */
    private function claim(Subscription $subscription): void
    {
        $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->first();

        if (! $locked instanceof Subscription) {
            return;
        }

        $subscription->setAttribute('current_period_start', $locked->current_period_start);
        $subscription->setAttribute('current_period_end', $locked->current_period_end);
        $subscription->setAttribute('cancel_at_period_end', $locked->cancel_at_period_end);
        $subscription->setAttribute('status', $locked->status);
        $subscription->setAttribute('paused_at', $locked->paused_at);
        $subscription->syncOriginal();
    }

    /**
     * Advance the subscription onto the cycle that follows its current period, computed
     * from the subscription's {@see BillingCycle} (anchor day + interval, month-end
     * clamped). Persists the new bounds and grants the opening slices of the new period.
     */
    private function advanceBase(Subscription $subscription, Plan $plan, DateTimeImmutable $now): BillingPeriod
    {
        $cycle = $this->cycleFor($subscription, $plan);
        $currentStart = $this->toImmutable($subscription->current_period_start ?? Carbon::now()->startOfMonth());

        // Anchor the advance on the period START (never the possibly-inclusive end) so the
        // step is exactly one cycle onto clean, month-end-clamped boundaries.
        $next = $cycle->nextPeriod($currentStart);

        $subscription->forceFill([
            'current_period_start' => Carbon::instance($next->start),
            'current_period_end' => Carbon::instance($next->end),
        ])->save();

        $this->provision($subscription, $plan, $next, $now);

        return $next;
    }

    /**
     * (Re)grant every add-on's per-cycle allotment on its own boundary. `periodFor`
     * resolves the period an add-on bills over — the base period when aligned, its own
     * cycle when independent — and the allotment is granted as a fresh lot keyed on that
     * period's start, so re-running grants nothing new and each cycle is funded once. The
     * opening cycle (already funded when the add-on was attached) is skipped.
     *
     * @return int the number of add-on cycles funded this run
     */
    private function renewAddOns(Subscription $subscription, BillingPeriod $basePeriod, DateTimeImmutable $now): int
    {
        $funded = 0;

        foreach ($subscription->addOns as $row) {
            $addOn = $row->toEngineAddOn();
            $period = $addOn->periodFor($basePeriod, $now);

            // The add-on's opening cycle was granted at attach time; only a cycle that
            // begins strictly after it was attached is a renewal to fund here.
            if ($this->toMillis($period->start) <= $this->createdAtMs($row)) {
                continue;
            }

            $allotment = $addOn->grantedAllotment($basePeriod, $now, CreditGrantMode::FullReset);

            if ($allotment <= 0) {
                continue;
            }

            $this->wallet->grant(new CreditGrant(
                id: sprintf('%s:addon:%s:%d', $subscription->organization_id, $row->key, $this->toMillis($period->start)),
                org: $subscription->organization_id,
                pool: Pools::included(),
                denomination: Denomination::unit('credit'),
                remaining: $allotment,
                expiresAt: $this->toMillis($period->end),
                grantedAt: $this->toMillis($period->start),
                kind: GrantKind::Base,
                cadence: GrantCadence::Once,
            ));

            $funded++;
        }

        return $funded;
    }

    /** Issue the renewal invoice for the new period once the base has rolled over. */
    private function invoiceRenewal(Subscription $subscription, bool $baseRenewed): ?Invoice
    {
        if (! $baseRenewed) {
            return null;
        }

        try {
            return $this->invoices->generate($subscription);
        } catch (RuntimeException) {
            // A tax-pending org (no resolvable billing address) cannot be invoiced yet; the
            // credit renewal still stands, and the monthly invoice/dunning passes catch up.
            return null;
        }
    }

    /** Deposit the plan's vested grant slices over `$period` at `$now` (idempotent). */
    private function provision(Subscription $subscription, Plan $plan, BillingPeriod $period, DateTimeImmutable $now): void
    {
        $this->provisioner->provision(
            $subscription->organization_id,
            $plan,
            $subscription->seats,
            $period->start,
            $period->end,
            $now,
        );
    }

    /** The subscription's current billing period as an engine value object. */
    private function basePeriod(Subscription $subscription): BillingPeriod
    {
        return new BillingPeriod(
            $this->toImmutable($subscription->current_period_start ?? Carbon::now()->startOfMonth()),
            $this->toImmutable($subscription->current_period_end ?? Carbon::now()->endOfMonth()),
        );
    }

    /**
     * The subscription's {@see BillingCycle}: its anchor day (and month) read from the
     * current period start, its interval from the plan, in UTC. Renewals advance on this
     * real, month-end-clamped cycle rather than an assumed 30-day window.
     */
    private function cycleFor(Subscription $subscription, Plan $plan): BillingCycle
    {
        $start = $subscription->current_period_start ?? Carbon::now()->startOfMonth();

        return new BillingCycle(
            anchorDay: (int) $start->format('j'),
            anchorMonth: (int) $start->format('n'),
            interval: $this->intervalFor($plan),
            zone: new DateTimeZone('UTC'),
        );
    }

    /** Map the plan's stored interval onto the engine's {@see BillingInterval}. */
    private function intervalFor(Plan $plan): BillingInterval
    {
        return $plan->billingInterval();
    }

    /** The plan with the collections the provisioner and invoicer read, eager-loaded. */
    private function planOf(Subscription $subscription): Plan
    {
        $plan = $subscription->plan;

        if (! $plan instanceof Plan) {
            throw new RuntimeException(sprintf('Subscription [%d] has no plan to renew.', $subscription->id));
        }

        $plan->loadMissing(['prices', 'product', 'creditGrants', 'entitlements.meter']);

        return $plan;
    }

    private function createdAtMs(SubscriptionAddOn $row): int
    {
        $createdAt = $row->created_at;

        return $createdAt === null ? 0 : $this->toMillis($createdAt->toDateTimeImmutable());
    }

    private function toImmutable(Carbon $carbon): DateTimeImmutable
    {
        return $carbon->toDateTimeImmutable();
    }

    private function toMillis(DateTimeImmutable $at): int
    {
        return $at->getTimestamp() * 1000;
    }
}
