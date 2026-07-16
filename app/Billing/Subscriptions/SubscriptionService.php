<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
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
        private TaxContextFactory $taxContexts,
        private ResolvesAccountCurrency $currencies,
    ) {}

    public function subscribe(Organization $organization, Plan $plan, int $seats = 1, ?string $currency = null): Subscription
    {
        [$periodStart, $periodEnd] = $this->currentPeriod();

        return $this->db->transaction(function () use ($organization, $plan, $seats, $currency, $periodStart, $periodEnd): Subscription {
            $this->pinCurrency($organization, $currency);

            $subscription = Subscription::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'plan_id' => $plan->id],
                [
                    'status' => SubscriptionStatus::Active,
                    'seats' => $seats,
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'cancel_at_period_end' => false,
                ],
            );

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

        return $preview;
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd): Subscription
    {
        if ($atPeriodEnd) {
            $subscription->forceFill(['cancel_at_period_end' => true])->save();

            return $subscription;
        }

        // Immediate: run the engine transition so forfeiture fires off the cancel-to-null
        // transition, then stamp the durable row canceled.
        $this->lifecycle->cancelNow($this->toEngineSubscription($subscription), $this->nowMillis());

        $subscription->forceFill([
            'status' => SubscriptionStatus::Canceled,
            'cancel_at_period_end' => false,
        ])->save();

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
        );
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
