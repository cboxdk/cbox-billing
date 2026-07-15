<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Tax\TaxContextFactory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\SubscriptionLifecycle;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\Subscription as EngineSubscription;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Subscribes organizations to plans, changes their plan, and cancels. The engine seams
 * wired here and nowhere else:
 *
 *  1. The durable {@see Subscription} row (which the meter-policy resolver already reads
 *     to answer "what is this dimension granted?").
 *  2. The org's {@see Wallet}: every plan credit-grant definition is projected into a
 *     real {@see CreditGrant} and deposited, so the plan's included allowance is spendable.
 *  3. The account's currency selection: a first subscribe pins the org's chosen currency,
 *     and every priced amount (proration, invoicing) runs in it.
 *  4. The engine's proration for a plan change — the preview IS the charge, so the amount
 *     due now is never a parallel estimate.
 *  5. The {@see SubscriptionLifecycle}: an immediate cancel runs a transition that
 *     forfeits the org's forfeitable pools as it leaves without landing on another plan.
 */
readonly class SubscriptionService implements SubscribesOrganizations
{
    public function __construct(
        private ConnectionInterface $db,
        private Wallet $wallet,
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

            $this->grantPlanCredits($organization->id, $plan, $seats, $periodStart, $periodEnd);

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
            $subscription->forceFill(['plan_id' => $newPlan->id])->save();

            $this->grantPlanCredits(
                $subscription->organization_id,
                $newPlan,
                $subscription->seats,
                $subscription->current_period_start ?? Carbon::now()->startOfMonth(),
                $subscription->current_period_end ?? Carbon::now()->endOfMonth(),
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

    /** Compute the proration consequence of a plan change in the account's currency. */
    private function buildPreview(Subscription $subscription, Plan $newPlan): PlanChangePreview
    {
        $organization = $subscription->organization
            ?? throw new RuntimeException(sprintf('Subscription [%d] has no organization.', $subscription->id));
        $currentPlan = $subscription->plan;
        $currency = $this->currencies->for($organization);

        $periodStart = $subscription->current_period_start ?? Carbon::now()->startOfMonth();
        $periodEnd = $subscription->current_period_end ?? Carbon::now()->endOfMonth();

        $period = new BillingPeriod(
            $this->toImmutable($periodStart),
            $this->toImmutable($periodEnd),
        );

        return $this->previewer->preview(
            currentPrice: $currentPlan?->priceFor($currency),
            newPrice: $newPlan->priceFor($currency),
            period: $period,
            at: $this->toImmutable(Carbon::now()),
            context: $this->taxContexts->forOrganization($organization),
            description: sprintf('Change to %s', $newPlan->name),
        );
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

    /** Project a plan's credit-grant definitions into wallet deposits. */
    private function grantPlanCredits(string $org, Plan $plan, int $seats, Carbon $periodStart, Carbon $periodEnd): void
    {
        foreach ($plan->creditGrants as $definition) {
            $amount = $definition->kind === GrantKind::PerSeat
                ? $definition->amount * max(1, $seats)
                : $definition->amount;

            if ($amount <= 0) {
                continue;
            }

            $pool = $this->pool($definition->pool);

            $this->wallet->grant(new CreditGrant(
                id: sprintf('%s:%s:%s', $org, $plan->key, Str::random(8)),
                org: $org,
                pool: $pool,
                denomination: $this->denomination($definition),
                remaining: $amount,
                expiresAt: $pool->requiresExpiry || $this->isRecurring($definition)
                    ? $this->toMillis($periodEnd)
                    : null,
                grantedAt: $this->toMillis($periodStart),
                kind: $definition->kind,
                cadence: $definition->cadence,
            ));
        }
    }

    private function isRecurring(PlanCreditGrant $definition): bool
    {
        return $definition->cadence === GrantCadence::Recurring;
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

    private function denomination(PlanCreditGrant $definition): Denomination
    {
        $code = $definition->denomination;

        // A three-letter upper-case code is treated as an ISO money denomination;
        // anything else (e.g. `credit`) is a meter/unit denomination.
        return preg_match('/^[A-Z]{3}$/', $code) === 1
            ? Denomination::money($code)
            : Denomination::unit($code);
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

    private function toMillis(Carbon $carbon): int
    {
        return (int) ($carbon->getTimestamp() * 1000);
    }

    private function nowMillis(): int
    {
        return $this->toMillis(Carbon::now());
    }
}
