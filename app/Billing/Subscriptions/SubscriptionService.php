<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Tax\TaxContextFactory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreviewer;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
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
 * Subscribes organizations to plans and changes their plan. Three engine seams are wired
 * here and nowhere else:
 *
 *  1. The durable {@see Subscription} row (which the meter-policy resolver already reads
 *     to answer "what is this dimension granted?").
 *  2. The org's {@see Wallet}: every plan credit-grant definition is projected into a
 *     real {@see CreditGrant} and deposited, so the plan's included allowance is spendable.
 *  3. The engine's proration for a plan change — the preview IS the charge, so the amount
 *     due now is never a parallel estimate.
 */
readonly class SubscriptionService implements SubscribesOrganizations
{
    public function __construct(
        private ConnectionInterface $db,
        private Wallet $wallet,
        private PlanChangePreviewer $previewer,
        private TaxContextFactory $taxContexts,
    ) {}

    public function subscribe(Organization $organization, Plan $plan, int $seats = 1): Subscription
    {
        [$periodStart, $periodEnd] = $this->currentPeriod();

        return $this->db->transaction(function () use ($organization, $plan, $seats, $periodStart, $periodEnd): Subscription {
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

    public function changePlan(Subscription $subscription, Plan $newPlan): PlanChangePreview
    {
        $organization = $subscription->organization
            ?? throw new RuntimeException(sprintf('Subscription [%d] has no organization.', $subscription->id));
        $currentPlan = $subscription->plan;

        $periodStart = $subscription->current_period_start ?? Carbon::now()->startOfMonth();
        $periodEnd = $subscription->current_period_end ?? Carbon::now()->endOfMonth();

        $period = new BillingPeriod(
            $this->toImmutable($periodStart),
            $this->toImmutable($periodEnd),
        );

        $preview = $this->previewer->preview(
            currentPrice: $currentPlan?->price(),
            newPrice: $newPlan->price(),
            period: $period,
            at: $this->toImmutable(Carbon::now()),
            context: $this->taxContexts->forOrganization($organization),
            description: sprintf('Change to %s', $newPlan->name),
        );

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
}
