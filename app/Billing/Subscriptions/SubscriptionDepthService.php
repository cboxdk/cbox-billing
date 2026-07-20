<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Mode\Contracts\BillingClock;
use App\Billing\Reporting\SubscriptionMrrMovementRecorder;
use App\Billing\Subscriptions\Contracts\CollectsProration;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\Exceptions\StaleAddOnPreview;
use App\Billing\Subscriptions\ValueObjects\AddOnPreview;
use App\Billing\Subscriptions\ValueObjects\AddOnRequest;
use App\Billing\Subscriptions\ValueObjects\QuantityPreview;
use App\Billing\Wallet\WalletProvisioner;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\CreditGrantMode;
use Cbox\Billing\Subscription\Enums\GatewayRounding;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use Cbox\Billing\Subscription\Proration\ProrationCalculator;
use Cbox\Billing\Subscription\ValueObjects\AddOn;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The subscription-management depth service (ADR-0012). It composes the engine's pure
 * primitives — the {@see ProrationCalculator} (seat and add-on proration, the same call
 * the charge makes), the {@see AddOn} value object (aligned vs independent period,
 * proration, allotment), and the {@see SubscribesOrganizations} plan-change flow (gated
 * by the transition policy) — with this app's durable rows and the org {@see Wallet}.
 *
 * Pause is an app-layer standing the engine's two-state status cannot express; a
 * deferred change is stored on the row and enacted when it comes due, kept distinct from
 * an immediate change; a seat change re-establishes the per-seat allotment for the new
 * count; an add-on's credit allotment lands in the wallet exactly as a plan grant does.
 */
readonly class SubscriptionDepthService implements ManagesSubscriptionDepth
{
    public function __construct(
        private ConnectionInterface $db,
        private Wallet $wallet,
        private WalletProvisioner $provisioner,
        private ProrationCalculator $proration,
        private ResolvesAccountCurrency $currencies,
        private SubscribesOrganizations $subscriptions,
        private SubscriptionMrrMovementRecorder $movements,
        private CollectsProration $collector,
        private GeneratesInvoices $invoices,
        private BillingClock $clock,
    ) {}

    public function pause(Subscription $subscription): Subscription
    {
        if (! $subscription->isPaused()) {
            $subscription->loadMissing('plan', 'organization');
            $previousMrr = $this->movements->contributing($subscription);

            $subscription->forceFill(['paused_at' => Carbon::now()])->save();

            // Pausing suspends billing: contributing MRR moves amount → 0 (recorded as churn,
            // the recoverable counterpart to the reactivation recorded when it resumes).
            $this->movements->record($subscription, $previousMrr, $this->movements->contributing($subscription));
        }

        return $subscription;
    }

    public function resume(Subscription $subscription): Subscription
    {
        if ($subscription->isPaused()) {
            $subscription->loadMissing('plan', 'organization');
            $previousMrr = $this->movements->contributing($subscription); // Paused → 0.

            $subscription->forceFill(['paused_at' => null])->save();

            // Resuming from pause restores previously-suspended MRR — a reactivation
            // (0 → the plan amount), the win-back counterpart to a churn.
            $this->movements->record($subscription, $previousMrr, $this->movements->contributing($subscription), returning: true);
        }

        return $subscription;
    }

    public function previewQuantity(Subscription $subscription, int $seats): QuantityPreview
    {
        $plan = $this->planOf($subscription);
        $currency = $this->currencies->for($this->organizationOf($subscription));

        // Price both seat counts through the plan's pricing model (M3): a flat plan is
        // seat-invariant, so from == to and the change nets zero; a per-unit plan scales by
        // seats; a tiered plan prices each count from its tier set. Never `base × seats`,
        // which over-charged a flat plan and ignored tiers.
        $charge = $this->proration->prorate(
            $plan->amountFor($currency, $subscription->seats),
            $plan->amountFor($currency, $seats),
            $this->basePeriod($subscription),
            $this->now(),
            GatewayRounding::HalfUp,
        );

        return new QuantityPreview($charge, $subscription->seats, $seats, $this->grossDueNow($subscription, $charge));
    }

    public function changeQuantity(Subscription $subscription, int $seats): QuantityPreview
    {
        $preview = $this->previewQuantity($subscription, $seats);
        $plan = $this->planOf($subscription);
        $subscription->loadMissing('organization');
        $previousMrr = $this->movements->contributing($subscription);

        $this->db->transaction(function () use ($subscription, $plan, $seats): void {
            $periodStart = SubscriptionPeriods::currentStart($subscription, $this->clock->now());
            $periodEnd = SubscriptionPeriods::currentEnd($subscription, $this->clock->now());

            $subscription->forceFill(['seats' => $seats])->save();

            // The included allotment is keyed by seat count in the wallet, so forfeit the
            // outgoing count's lots and re-grant at the new count: a per-seat grant
            // rescales, a flat allowance re-establishes to the same size (ADR-0011).
            $this->wallet->forfeit($subscription->organization_id, $this->nowMillis());

            $this->provisioner->provision(
                $subscription->organization_id,
                $plan,
                $seats,
                $periodStart->toDateTimeImmutable(),
                $periodEnd->toDateTimeImmutable(),
                $this->now(),
            );
        });

        // Record the resulting MRR movement. Contributing MRR is seat-aware, so a seat change
        // on a per-unit/tiered plan moves it: this logs the expansion (seats up) or
        // contraction (seats down), prev seats → new seats priced by the engine. A flat plan
        // does not move (seats do not change its amount), so record() no-ops on the equal edges.
        $this->movements->record($subscription, $previousMrr, $this->movements->contributing($subscription->loadMissing('plan.prices.tiers')));

        // Collect the previewed seat-delta proration (H6): a seat increase is charged over the
        // days still to run, not provisioned free. A flat plan nets zero and a reduction nets a
        // credit — both owe nothing now, so the collector no-ops.
        $this->collector->collect($subscription, $preview->charge, sprintf('Seat change to %d', $seats));

        return $preview;
    }

    public function previewAddOn(Subscription $subscription, AddOnRequest $request): AddOnPreview
    {
        $addOn = $this->engineAddOn($request);
        $basePeriod = $this->basePeriod($subscription);
        $at = $this->now();

        $charge = $addOn->proratedCharge($this->proration, $basePeriod, $at, GatewayRounding::HalfUp);
        $allotment = $addOn->grantedAllotment($basePeriod, $at, CreditGrantMode::Prorated);
        $period = $addOn->periodFor($basePeriod, $at);

        return new AddOnPreview(
            charge: $charge,
            // The tax-aware GROSS actually collected (the net taxed through the same quote the
            // apply path issues) — so the "Due now" a preview shows equals the charge.
            grossDueNow: $this->grossDueNow($subscription, $charge),
            allotment: $allotment,
            alignment: $request->alignment,
            periodEnd: $period->end,
        );
    }

    public function addAddOn(Subscription $subscription, AddOnRequest $request): SubscriptionAddOn
    {
        $addOn = $this->engineAddOn($request);
        $basePeriod = $this->basePeriod($subscription);
        $at = $this->now();
        $period = $addOn->periodFor($basePeriod, $at);
        $allotment = $addOn->grantedAllotment($basePeriod, $at, CreditGrantMode::Prorated);
        $charge = $addOn->proratedCharge($this->proration, $basePeriod, $at, GatewayRounding::HalfUp);

        // Snapshot guard: when the caller previewed a "due now" gross, refuse to apply if the fresh
        // proration has drifted (preview and confirm straddled a period boundary). This makes a
        // confirm charge exactly the previewed gross, or fail loudly — never a silently different amount.
        if ($request->expectedGrossDueMinor !== null) {
            $grossDueNow = $this->grossDueNow($subscription, $charge);

            if ($grossDueNow->minor() !== $request->expectedGrossDueMinor) {
                throw StaleAddOnPreview::mismatch($request->expectedGrossDueMinor, $grossDueNow->minor(), $grossDueNow->currency());
            }
        }

        $row = $this->db->transaction(function () use ($subscription, $request, $period, $allotment): SubscriptionAddOn {
            $row = SubscriptionAddOn::query()->updateOrCreate(
                ['subscription_id' => $subscription->id, 'key' => $request->key],
                [
                    'price_minor' => $request->priceMinor,
                    'currency' => $request->currency,
                    'alignment' => $request->alignment,
                    'credit_allotment' => $request->creditAllotment,
                    'anchor_day' => $request->anchorDay,
                    'anchor_month' => $request->anchorMonth,
                    'interval' => $request->interval,
                ],
            );

            // The add-on's per-cycle allotment lands in the wallet as an included-pool
            // credit grant, expiring at its own resolved period end (aligned or
            // independent) — the same shape a plan's allotment takes.
            if ($allotment > 0) {
                $this->wallet->grant(new CreditGrant(
                    id: sprintf('%s:addon:%s', $subscription->organization_id, $request->key),
                    org: $subscription->organization_id,
                    pool: Pools::included(),
                    denomination: Denomination::unit('credit'),
                    remaining: $allotment,
                    expiresAt: $this->toMillis($period->end),
                    grantedAt: $this->nowMillis(),
                    kind: GrantKind::Base,
                    cadence: GrantCadence::Once,
                ));
            }

            return $row;
        });

        // Collect the previewed prorated add-on charge (H6): attaching an add-on mid-cycle is
        // charged over the days still to run, not granted free. A zero-priced add-on owes
        // nothing, so the collector no-ops.
        $this->collector->collect($subscription->loadMissing('organization'), $charge, sprintf('Add-on “%s”', $request->key));

        return $row;
    }

    public function removeAddOn(Subscription $subscription, string $key): bool
    {
        return $subscription->addOns()->where('key', $key)->delete() > 0;
    }

    public function scheduleChange(Subscription $subscription, Plan $newPlan): PlanChangePreview
    {
        // Gate the transition exactly as an immediate change would (ADR-0010): a
        // disallowed target raises before anything is stored.
        $preview = $this->subscriptions->previewChange($subscription, $newPlan);

        $subscription->forceFill([
            'pending_plan_id' => $newPlan->id,
            'pending_effective_at' => SubscriptionPeriods::currentEnd($subscription, $this->clock->now()),
        ])->save();

        return $preview;
    }

    public function applyDueScheduledChanges(): int
    {
        $now = $this->clock->now();

        $due = Subscription::query()
            ->whereNotNull('pending_plan_id')
            ->whereNotNull('pending_effective_at')
            ->where('pending_effective_at', '<=', $now)
            ->serving()
            ->with(['plan.product', 'organization'])
            ->get();

        $applied = 0;

        foreach ($due as $subscription) {
            $newPlan = Plan::query()->with(['prices', 'product'])->find($subscription->pending_plan_id);

            if (! $newPlan instanceof Plan) {
                $subscription->forceFill(['pending_plan_id' => null, 'pending_effective_at' => null])->save();

                continue;
            }

            $this->subscriptions->changePlan($subscription, $newPlan);

            $subscription->forceFill(['pending_plan_id' => null, 'pending_effective_at' => null])->save();
            $applied++;
        }

        return $applied;
    }

    /** Build the engine {@see AddOn} from a request, defaulting an independent cycle to now. */
    private function engineAddOn(AddOnRequest $request): AddOn
    {
        $addOn = new SubscriptionAddOn([
            'key' => $request->key,
            'price_minor' => $request->priceMinor,
            'currency' => $request->currency,
            'alignment' => $request->alignment,
            'credit_allotment' => $request->creditAllotment,
            'anchor_day' => $request->anchorDay ?? ($request->isIndependent() ? (int) $this->now()->format('j') : null),
            'anchor_month' => $request->anchorMonth ?? ($request->isIndependent() ? (int) $this->now()->format('n') : null),
            'interval' => $request->interval,
        ]);

        return $addOn->toEngineAddOn();
    }

    private function basePeriod(Subscription $subscription): BillingPeriod
    {
        return new BillingPeriod(
            (SubscriptionPeriods::currentStart($subscription, $this->clock->now()))->toDateTimeImmutable(),
            (SubscriptionPeriods::currentEnd($subscription, $this->clock->now()))->toDateTimeImmutable(),
        );
    }

    private function planOf(Subscription $subscription): Plan
    {
        $plan = $subscription->plan;

        if (! $plan instanceof Plan) {
            throw new RuntimeException(sprintf('Subscription [%d] has no plan.', $subscription->id));
        }

        $plan->loadMissing(['prices.tiers', 'product']);

        return $plan;
    }

    private function organizationOf(Subscription $subscription): Organization
    {
        $organization = $subscription->organization;

        if ($organization === null) {
            throw new RuntimeException(sprintf('Subscription [%d] has no organization.', $subscription->id));
        }

        return $organization;
    }

    /**
     * The tax-aware GROSS a positive proration will actually collect — the same figure the
     * apply path's collector charges, run through the shared invoice quote seam so preview ==
     * charge by construction. A non-positive proration (a credit / net-zero change) owes
     * nothing now, so its gross due-now is zero.
     */
    private function grossDueNow(Subscription $subscription, Money $charge): Money
    {
        if (! $charge->isPositive()) {
            return Money::zero($charge->currency());
        }

        return $this->invoices->grossDueNow($subscription->loadMissing('organization'), $charge);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->toDateTimeImmutable();
    }

    private function nowMillis(): int
    {
        return $this->clock->now()->getTimestampMs();
    }

    private function toMillis(DateTimeImmutable $at): int
    {
        return $at->getTimestamp() * 1000;
    }
}
