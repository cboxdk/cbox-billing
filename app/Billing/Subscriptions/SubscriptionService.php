<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Tax\TaxContextFactory;
use App\Billing\Wallet\WalletProvisioner;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Contracts\TransitionPolicy;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\PlanChange\ValueObjects\CreditConsequenceRequest;
use Cbox\Billing\Subscription\SubscriptionLifecycle;
use Cbox\Billing\Subscription\SubscriptionManager;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\Subscription as EngineSubscription;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Subscribes organizations to plans, changes their plan, and cancels. The engine seams
 * wired here and nowhere else:
 *
 *  1. The durable {@see Subscription} row (which the meter-policy resolver already reads
 *     to answer "what is this dimension granted?").
 *  2. The org's {@see Wallet}: every plan grant — the authored credit-pool grants AND
 *     each meter's included allowance (ADR-0013) — is projected into real wallet lots by
 *     the {@see WalletProvisioner}, so the plan's included allowance is a spendable
 *     balance the allowance resolver sources rather than a hand-authored scalar.
 *  3. The account's currency selection: a first subscribe pins the org's chosen currency,
 *     and every priced amount (proration, invoicing) runs in it.
 *  4. The engine's proration + {@see TransitionPolicy}
 *     for a plan change — the transition is gated before proration (ADR-0010) and the
 *     preview IS the charge, so the amount due now is never a parallel estimate.
 *  5. The {@see SubscriptionLifecycle}: an immediate cancel runs a transition that
 *     forfeits the org's forfeitable pools as it leaves without landing on another plan;
 *     a plan switch resets the outgoing included allotment before regranting (ADR-0011).
 */
readonly class SubscriptionService implements SubscribesOrganizations
{
    public function __construct(
        private ConnectionInterface $db,
        private Wallet $wallet,
        private WalletProvisioner $provisioner,
        private PlanChangePreviewer $previewer,
        private SubscriptionLifecycle $lifecycle,
        private SubscriptionManager $manager,
        private TaxContextFactory $taxContexts,
        private ResolvesAccountCurrency $currencies,
        private NotifiesCustomers $notifier,
    ) {}

    public function subscribe(Organization $organization, Plan $plan, int $seats = 1, ?string $currency = null): Subscription
    {
        return $this->open($organization, $plan, $seats, $currency, null);
    }

    public function subscribeWithTrial(Organization $organization, Plan $plan, ?int $trialDays = null, int $seats = 1, ?string $currency = null): Subscription
    {
        $trialDays ??= $this->configuredTrialDays();

        // Deny-by-default on a nonsensical length: a zero/negative trial is an ordinary
        // paid subscribe (Active from the start), never a Trialing row that converts in
        // the past.
        $trialEndsAt = $trialDays > 0 ? Carbon::now()->addDays($trialDays)->toDateTimeImmutable() : null;

        return $this->open($organization, $plan, $seats, $currency, $trialEndsAt);
    }

    /**
     * Convert a due trial to a paying subscription (first charge is raised by the caller):
     * the engine's `Trialing` → `Active` transition, then the durable row is stamped
     * `Active` and the trial marker cleared. Refuses (via the engine machine) to convert a
     * subscription that is not `Trialing`.
     */
    public function convertTrial(Subscription $subscription): Subscription
    {
        $converted = $this->manager->convertTrial($this->toEngineSubscription($subscription), $this->now());

        $subscription->forceFill([
            'status' => $converted->status,
            'trial_ends_at' => null,
        ])->save();

        return $subscription;
    }

    /**
     * A failed renewal charge: move a serving subscription to the engine's `PastDue` state
     * so the smart-retry schedule can chase it. Idempotent — an already-`PastDue`
     * subscription is left as-is (the machine permits the `PastDue` → `PastDue` self-loop).
     */
    public function markPastDue(Subscription $subscription): Subscription
    {
        $updated = $this->manager->markPastDue($this->toEngineSubscription($subscription));

        $subscription->forceFill(['status' => $updated->status])->save();

        return $subscription;
    }

    /** A recovered payment: the engine's `PastDue` → `Active` transition, persisted. */
    public function recover(Subscription $subscription): Subscription
    {
        $updated = $this->manager->recover($this->toEngineSubscription($subscription));

        $subscription->forceFill(['status' => $updated->status])->save();

        return $subscription;
    }

    /** Open a subscription for the current period, optionally opening it in a trial. */
    private function open(Organization $organization, Plan $plan, int $seats, ?string $currency, ?DateTimeImmutable $trialEndsAt): Subscription
    {
        [$periodStart, $periodEnd] = $this->currentPeriod();

        return $this->db->transaction(function () use ($organization, $plan, $seats, $currency, $periodStart, $periodEnd, $trialEndsAt): Subscription {
            $this->pinCurrency($organization, $currency);

            $subscription = Subscription::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'plan_id' => $plan->id],
                [
                    // A trial opens Trialing (serving, charging nothing); otherwise Active.
                    'status' => $trialEndsAt !== null ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
                    'seats' => $seats,
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'cancel_at_period_end' => false,
                    'trial_ends_at' => $trialEndsAt !== null ? Carbon::instance($trialEndsAt) : null,
                    'canceled_at' => null,
                ],
            );

            // A trial is a serving state, so its plan grants are provisioned exactly as a
            // paid subscribe's are — the customer gets full entitlements during the trial.
            $this->provisioner->provision(
                $organization->id,
                $plan,
                $seats,
                $this->toImmutable($periodStart),
                $this->toImmutable($periodEnd),
                $this->toImmutable(Carbon::now()),
            );

            return $subscription;
        });
    }

    public function previewChange(Subscription $subscription, Plan $newPlan): PlanChangePreview
    {
        return $this->buildPreview($subscription, $newPlan);
    }

    public function changePlan(Subscription $subscription, Plan $newPlan): PlanChangePreview
    {
        $preview = $this->buildPreview($subscription, $newPlan);
        $previousPlanName = $subscription->plan?->name;

        $this->db->transaction(function () use ($subscription, $newPlan): void {
            $periodStart = $subscription->current_period_start ?? Carbon::now()->startOfMonth();
            $periodEnd = $subscription->current_period_end ?? Carbon::now()->endOfMonth();

            $subscription->forceFill(['plan_id' => $newPlan->id])->save();

            // Per-cycle reset (ADR-0011): forfeit the outgoing plan's forfeitable
            // (`included`) allotment before the incoming plan's is granted, so the
            // included allowance is the new plan's, never the sum of both.
            $this->wallet->forfeit($subscription->organization_id, $this->nowMillis());

            $this->provisioner->provision(
                $subscription->organization_id,
                $newPlan,
                $subscription->seats,
                $this->toImmutable($periodStart),
                $this->toImmutable($periodEnd),
                $this->toImmutable(Carbon::now()),
            );
        });

        // Confirm the switch to the billing contact (the new plan is now on the row).
        $this->notifier->subscriptionChanged($subscription->refresh()->loadMissing('plan', 'organization'), 'plan_change', $previousPlanName);

        return $preview;
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd): Subscription
    {
        if ($atPeriodEnd) {
            $subscription->forceFill(['cancel_at_period_end' => true])->save();

            $this->notifier->subscriptionChanged($subscription->loadMissing('plan', 'organization'), 'cancel_scheduled');

            return $subscription;
        }

        // Immediate: run the engine transition so forfeiture fires off the cancel-to-null
        // transition, then stamp the durable row canceled.
        $this->lifecycle->cancelNow($this->toEngineSubscription($subscription), $this->nowMillis());

        $subscription->forceFill([
            'status' => SubscriptionStatus::Canceled,
            'cancel_at_period_end' => false,
            'canceled_at' => Carbon::now(),
        ])->save();

        $this->notifier->subscriptionChanged($subscription->loadMissing('plan', 'organization'), 'canceled');

        return $subscription;
    }

    /** Compute the proration + credit consequence of a plan change in the account's currency. */
    private function buildPreview(Subscription $subscription, Plan $newPlan): PlanChangePreview
    {
        $organization = $subscription->organization
            ?? throw new RuntimeException(sprintf('Subscription [%d] has no organization.', $subscription->id));

        $currentPlan = $subscription->plan;

        if (! $currentPlan instanceof Plan) {
            throw new RuntimeException(sprintf('Subscription [%d] has no plan to change from.', $subscription->id));
        }

        $currentPlan->loadMissing('product');
        $newPlan->loadMissing('product');

        $currency = $this->currencies->for($organization);

        $period = new BillingPeriod(
            $this->toImmutable($subscription->current_period_start ?? Carbon::now()->startOfMonth()),
            $this->toImmutable($subscription->current_period_end ?? Carbon::now()->endOfMonth()),
        );

        return $this->previewer->preview(
            fromPlan: $currentPlan->toCatalogProduct(),
            toPlan: $newPlan->toCatalogProduct(),
            currentPrice: $currentPlan->priceFor($currency),
            newPrice: $newPlan->priceFor($currency),
            period: $period,
            at: $this->toImmutable(Carbon::now()),
            context: $this->taxContexts->forOrganization($organization),
            credit: $this->creditConsequence($organization->id, $newPlan),
            description: sprintf('Change to %s', $newPlan->name),
        );
    }

    /**
     * The wallet-derived inputs the previewer projects the credit consequence from
     * (ADR-0011): the outgoing plan's unspent recurring allotment, the incoming plan's
     * allotment, and the surviving pay-as-you-go balance — all read here so the previewer
     * stays a pure function of its inputs.
     */
    private function creditConsequence(string $org, Plan $newPlan): CreditConsequenceRequest
    {
        $now = $this->nowMillis();
        $allotment = Denomination::unit('credit');

        return new CreditConsequenceRequest(
            outgoingAllotmentRemaining: max(0, $this->wallet->balance($org, Pools::included(), $allotment, $now)),
            incomingAllotment: $this->includedCreditAllotment($newPlan),
            payAsYouGoBalance: $this->wallet->balance($org, Pools::purchased(), $allotment, $now),
        );
    }

    /** The plan's recurring `included` credit allotment (the `credit`-denominated grant), or 0. */
    private function includedCreditAllotment(Plan $plan): int
    {
        foreach ($plan->creditGrants as $grant) {
            if ($grant->pool === Pools::INCLUDED && $grant->denomination === 'credit') {
                return $grant->amount;
            }
        }

        return 0;
    }

    /** Record the account's chosen currency the first time it subscribes. */
    private function pinCurrency(Organization $organization, ?string $currency): void
    {
        if ($organization->billing_currency !== null) {
            return;
        }

        $organization->forceFill([
            'billing_currency' => $currency ?? $this->currencies->for($organization),
        ])->save();
    }

    /** Project the durable row into the engine's immutable subscription value object. */
    private function toEngineSubscription(Subscription $subscription): EngineSubscription
    {
        $plan = $subscription->plan;

        if (! $plan instanceof Plan) {
            throw new RuntimeException(sprintf('Subscription [%d] has no plan to cancel.', $subscription->id));
        }

        $period = new BillingPeriod(
            $this->toImmutable($subscription->current_period_start ?? Carbon::now()->startOfMonth()),
            $this->toImmutable($subscription->current_period_end ?? Carbon::now()->endOfMonth()),
        );

        return new EngineSubscription(
            id: (string) $subscription->id,
            organizationId: $subscription->organization_id,
            productId: (string) $plan->product_id,
            priceId: $plan->key,
            period: $period,
            // Carry the durable row's real state so an engine transition is validated
            // against where the subscription actually is (a Trialing → Active convert, a
            // PastDue → Active recover), not an assumed Active.
            status: $subscription->status,
            cancelAtPeriodEnd: $subscription->cancel_at_period_end,
            trialEndsAt: $subscription->trial_ends_at?->toDateTimeImmutable(),
        );
    }

    private function configuredTrialDays(): int
    {
        $days = config('billing.trial.default_days', 14);

        return is_numeric($days) ? (int) $days : 14;
    }

    private function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function currentPeriod(): array
    {
        $now = Carbon::now();

        return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
    }

    private function toImmutable(Carbon $carbon): DateTimeImmutable
    {
        return $carbon->toDateTimeImmutable();
    }

    private function nowMillis(): int
    {
        return (int) (Carbon::now()->getTimestamp() * 1000);
    }
}
