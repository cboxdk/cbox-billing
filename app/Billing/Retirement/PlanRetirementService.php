<?php

declare(strict_types=1);

namespace App\Billing\Retirement;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Catalog\PlanCatalog;
use App\Billing\Mode\Contracts\BillingClock;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Retirement\ValueObjects\SunsetNotice;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\CycleRenewalService;
use App\Billing\Subscriptions\SubscriptionPeriods;
use App\Billing\Support\MoneyFormatter;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanRetirementEvent;
use App\Models\Subscription;
use Cbox\Billing\Catalog\Contracts\Catalog;
use Cbox\Billing\Subscription\Retirement\Enums\RetirementOutcome;
use Cbox\Billing\Subscription\Retirement\Exceptions\RetirementNotResolved;
use Cbox\Billing\Subscription\Retirement\PlanRetirementResolver;
use Cbox\Billing\Subscription\Retirement\RetirementRenewalPolicy;
use Cbox\Billing\Subscription\Retirement\RetirementResolution;
use Cbox\Billing\Subscription\ValueObjects\BillingPeriod;
use Cbox\Billing\Subscription\ValueObjects\ScheduledChange;
use Cbox\Billing\Subscription\ValueObjects\Subscription as EngineSubscription;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The plan-sunset flow (ADR-0016). It drives the engine's retirement seam from the app's
 * durable rows: it resolves a subscription against its plan's retirement via the pure
 * {@see PlanRetirementResolver} to render the sunset notice, records the subscriber's three
 * first-class choices (a scheduled successor, a scheduled cancel, or nothing → the default),
 * and — at the migration pass — runs the engine {@see RetirementRenewalPolicy} to enact the
 * verdict and reconcile the durable subscription, deny-by-default flagging any subscriber a
 * retired plan would otherwise silently charge.
 *
 * The engine resolver/policy consume the {@see Catalog} contract keyed by plan **key**, so
 * this service projects the durable catalog through {@see PlanCatalog} and builds the engine
 * subscription value object with `productId = plan.key` (the id the retirement rides on),
 * carrying the subscriber's scheduled election as the engine {@see ScheduledChange}.
 */
readonly class PlanRetirementService
{
    public function __construct(
        private ConnectionInterface $db,
        private ResolvesAccountCurrency $currencies,
        private PlanRetirementResolver $resolver,
        private RetirementRenewalPolicy $policy,
        private CycleRenewalService $renewals,
        private SubscribesOrganizations $subscriptions,
        private ManagesSubscriptionDepth $depth,
        private NotifiesCustomers $notifier,
        private BillingClock $clock,
    ) {}

    /**
     * Resolve `$subscription` against its plan's retirement at `$now` — the pure engine
     * verdict (NotRetiring / RetiringChooseBy / ResolvedTo… / Unresolved), with no side
     * effects. The single decision the notice, the migration, and the reminder all read.
     */
    public function resolutionFor(Subscription $subscription, ?DateTimeImmutable $now = null): RetirementResolution
    {
        $now ??= $this->now();
        $plan = $this->planOf($subscription);
        $currency = $this->currencies->for($this->organizationOf($subscription));

        return $this->resolver->resolve(
            $this->engineSubscription($subscription, $plan, $currency),
            PlanCatalog::for($currency),
            $now,
        );
    }

    /**
     * The sunset notice for `$subscription`, or null when its plan is not being retired.
     * Carries the cutoff, the renewal-due deadline, the default, the subscriber's current
     * election, and the successor pick-list — everything the portal and console render.
     */
    public function noticeFor(Subscription $subscription, ?DateTimeImmutable $now = null): ?SunsetNotice
    {
        $plan = $subscription->plan;

        if (! $plan instanceof Plan || ! $plan->isRetiring()) {
            return null;
        }

        $now ??= $this->now();
        $resolution = $this->resolutionFor($subscription, $now);

        // The renewal-due date the resolver computed (carried on RetiringChooseBy); once the
        // renewal is due it is the period end.
        $renewalDue = $resolution->renewalDueDate
            ?? $subscription->current_period_end?->toDateTimeImmutable()
            ?? $now;

        $default = $plan->defaultSuccessor;
        $currency = $this->currencies->for($this->organizationOf($subscription));

        return new SunsetNotice(
            planName: $plan->name,
            retiresAt: $this->label($plan->retires_at?->toDateTimeImmutable() ?? $now),
            renewalDue: $this->label($renewalDue),
            defaultSuccessorKey: $default?->key,
            defaultSuccessorName: $default?->name,
            election: $this->election($subscription),
            electedSuccessorName: $subscription->pendingPlan?->name,
            successors: $this->successorOptions($plan, $currency),
            unresolved: $resolution->isUnresolved(),
        );
    }

    /**
     * Elect a successor (choice #1): schedule a plan change to `$successor` for the period
     * end, gated by the same transition policy an immediate change is. The scheduled change
     * is what the engine resolver reads as `ResolvedToSuccessor` at the migration.
     */
    public function electSuccessor(Subscription $subscription, Plan $successor): void
    {
        $this->depth->scheduleChange($subscription, $successor);
    }

    /**
     * Elect a cancel (choice #2): schedule a period-end cancel. The subscriber keeps
     * serving until the renewal, where the retirement is enacted as a cancel.
     */
    public function electCancel(Subscription $subscription): void
    {
        $this->subscriptions->cancel($subscription, atPeriodEnd: true);
    }

    /**
     * The serving subscriptions whose plan is retired as of `$now` and whose renewal is due
     * — the candidates the migration pass enacts. Deny-by-default: only past-cutoff, due
     * subscriptions are migrated; a subscriber mid-period keeps their paid time.
     *
     * @return Collection<int, Subscription>
     */
    public function dueForMigration(?DateTimeImmutable $now = null): Collection
    {
        $now ??= $this->now();

        return $this->retiringSubscriptions($now)
            ->filter(function (Subscription $subscription) use ($now): bool {
                $end = $subscription->current_period_end?->toDateTimeImmutable();

                return $end !== null && $end <= $now;
            })
            ->values();
    }

    /**
     * Enact the due retirement for `$subscription` by running the engine
     * {@see RetirementRenewalPolicy}, then reconcile the durable row and record the
     * migration (ADR-0016). Idempotent per retirement window: an already-migrated (or
     * already-flagged) subscription is skipped. Returns the recorded outcome value, or null
     * when nothing was due.
     */
    public function migrate(Subscription $subscription, ?DateTimeImmutable $now = null): ?string
    {
        $now ??= $this->now();
        $plan = $this->planOf($subscription);
        $retiresAt = $plan->retires_at?->toDateTimeImmutable();

        if ($retiresAt === null || $this->alreadyHandled($subscription, $retiresAt)) {
            return null;
        }

        $currency = $this->currencies->for($this->organizationOf($subscription));
        $engine = $this->engineSubscription($subscription, $plan, $currency);
        $catalog = PlanCatalog::for($currency);
        $nextPeriod = $this->nextPeriod($subscription, $plan);

        try {
            $enacted = $this->policy->renew($engine, $catalog, $nextPeriod, $now);
        } catch (RetirementNotResolved) {
            // Deny-by-default: no choice, no default. Flag it for ops and do NOT renew — the
            // subscription stays on the retired plan, blocked from charging.
            $this->record($subscription, $plan, $retiresAt, PlanRetirementEvent::TYPE_UNRESOLVED, RetirementOutcome::UnresolvedRetirement->value, null, 'No successor chosen, no cancel scheduled, no default configured.');

            return RetirementOutcome::UnresolvedRetirement->value;
        }

        // The subscriber scheduled a cancel: the renewal ends the subscription.
        if ($enacted->isCanceled()) {
            $this->renewals->renew($subscription, $now);
            $this->record($subscription, $plan, $retiresAt, PlanRetirementEvent::TYPE_MIGRATED, RetirementOutcome::ResolvedToCancel->value, null, 'Canceled at renewal.');

            return RetirementOutcome::ResolvedToCancel->value;
        }

        // Migrated onto a successor (chosen) or the default — the engine returned a new
        // product id (a plan key). Move the durable row onto it and renew on the successor.
        if ($enacted->productId !== $plan->key) {
            $successor = Plan::query()->where('key', $enacted->productId)->firstOrFail();
            $chosen = $subscription->pending_plan_id !== null;
            $outcome = $chosen ? RetirementOutcome::ResolvedToSuccessor : RetirementOutcome::ResolvedToDefault;

            $this->migrateOnto($subscription, $successor, $now);
            $this->record($subscription, $plan, $retiresAt, PlanRetirementEvent::TYPE_MIGRATED, $outcome->value, $successor->id, sprintf('Migrated to %s.', $successor->name));

            return $outcome->value;
        }

        return null;
    }

    /**
     * Send the plan-retiring reminder to every affected billing contact within `$leadDays`
     * of a cutoff, once per subscription per retirement window (idempotent). Returns the
     * number of reminders queued.
     */
    public function remindAffected(int $leadDays, ?DateTimeImmutable $now = null, ?string $org = null): int
    {
        $now ??= $this->now();
        $sent = 0;

        foreach ($this->retiringSubscriptions($now, includeFuture: true) as $subscription) {
            if ($org !== null && $subscription->organization_id !== $org) {
                continue;
            }

            $plan = $subscription->plan;

            if (! $plan instanceof Plan) {
                continue;
            }

            $retiresAt = $plan->retires_at?->toDateTimeImmutable();

            if ($retiresAt === null) {
                continue;
            }

            // Only within the lead window ahead of the cutoff, and not already reminded.
            $leadStart = Carbon::instance($retiresAt)->subDays($leadDays)->toDateTimeImmutable();

            if ($now < $leadStart || $this->reminded($subscription, $retiresAt)) {
                continue;
            }

            $notice = $this->noticeFor($subscription, $now);

            if ($notice === null) {
                continue;
            }

            $this->notifier->planRetiring($subscription, $plan, $notice->retiresAt, $notice->renewalDue, $notice->defaultSuccessorName);
            $this->record($subscription, $plan, $retiresAt, PlanRetirementEvent::TYPE_REMINDER, null, null, 'Reminder queued.');
            $sent++;
        }

        return $sent;
    }

    /** The subscriptions ops must still resolve: flagged unresolved and not since migrated. */
    /** @return Collection<int, PlanRetirementEvent> */
    public function unresolved(): Collection
    {
        return PlanRetirementEvent::query()
            ->with(['subscription.organization', 'plan'])
            ->where('type', PlanRetirementEvent::TYPE_UNRESOLVED)
            ->orderByDesc('created_at')
            ->get();
    }

    /** Change the durable plan to the successor, then renew (provision + invoice) on it. */
    private function migrateOnto(Subscription $subscription, Plan $successor, DateTimeImmutable $now): void
    {
        $this->db->transaction(function () use ($subscription, $successor): void {
            $subscription->forceFill([
                'plan_id' => $successor->id,
                'pending_plan_id' => null,
                'pending_effective_at' => null,
            ])->save();
        });

        // The retired-plan guard in the renewal skips a still-retired plan; the durable row
        // is now on the (non-retired) successor, so this renews and invoices normally on it.
        $subscription->refresh()->loadMissing(['plan.prices', 'plan.product', 'plan.creditGrants', 'plan.entitlements.meter', 'organization']);
        $this->renewals->renew($subscription, $now);
    }

    /** The candidate subscriptions on retiring plans. */
    /** @return Collection<int, Subscription> */
    private function retiringSubscriptions(DateTimeImmutable $now, bool $includeFuture = false): Collection
    {
        // The retiring plans in scope: every plan with a cutoff, narrowed to past-cutoff
        // (retired) plans unless future cutoffs are wanted (the reminder pass wants them).
        $plans = Plan::query()->whereNotNull('retires_at');

        if (! $includeFuture) {
            $plans->where('retires_at', '<=', Carbon::instance($now));
        }

        $retiringPlanIds = $plans->pluck('id')->all();

        if ($retiringPlanIds === []) {
            return new Collection;
        }

        return Subscription::query()
            ->with(['plan.prices', 'plan.defaultSuccessor', 'plan.product', 'pendingPlan', 'organization'])
            ->whereIn('plan_id', $retiringPlanIds)
            ->whereIn('status', Subscription::servingStatuses())
            ->whereNull('paused_at')
            ->get();
    }

    /** Build the engine subscription value object for `$plan`, carrying any scheduled election. */
    private function engineSubscription(Subscription $subscription, Plan $plan, string $currency): EngineSubscription
    {
        $period = new BillingPeriod(
            $this->toImmutable(SubscriptionPeriods::currentStart($subscription, Carbon::now())),
            $this->toImmutable(SubscriptionPeriods::currentEnd($subscription, Carbon::now())),
        );

        return new EngineSubscription(
            id: (string) $subscription->id,
            organizationId: $subscription->organization_id,
            productId: $plan->key,
            priceId: $this->priceId($plan, $currency),
            period: $period,
            status: $subscription->status,
            cancelAtPeriodEnd: $subscription->cancel_at_period_end,
            pendingChange: $this->scheduledChange($subscription, $currency),
            cycle: SubscriptionPeriods::cycleFor($subscription, $plan, Carbon::now()),
        );
    }

    /** The subscriber's scheduled successor election as the engine ScheduledChange, or null. */
    private function scheduledChange(Subscription $subscription, string $currency): ?ScheduledChange
    {
        $successor = $subscription->pendingPlan;

        if (! $successor instanceof Plan) {
            return null;
        }

        return new ScheduledChange(
            newPriceId: $this->priceId($successor, $currency),
            effectiveAt: $this->toImmutable($subscription->pending_effective_at ?? SubscriptionPeriods::currentEnd($subscription, Carbon::now())),
            newProductId: $successor->key,
        );
    }

    /** The plan's price id in the account currency, or the plan key as a stable fallback. */
    private function priceId(Plan $plan, string $currency): string
    {
        $plan->loadMissing('prices');
        $price = $plan->prices->firstWhere('currency', $currency);

        return $price !== null ? (string) $price->id : $plan->key;
    }

    /** The successor pick-list: active, non-retiring plans priced in `$currency`, minus this one. */
    /** @return list<array{key: string, name: string, price: string}> */
    private function successorOptions(Plan $plan, string $currency): array
    {
        return array_values(Plan::query()
            ->with('prices')
            ->where('active', true)
            ->whereNull('retires_at')
            ->where('key', '!=', $plan->key)
            ->orderBy('id')
            ->get()
            ->filter(static fn (Plan $candidate): bool => $candidate->prices->contains('currency', $currency))
            ->map(static function (Plan $candidate) use ($currency): array {
                return [
                    'key' => $candidate->key,
                    'name' => $candidate->name,
                    'price' => MoneyFormatter::money($candidate->priceFor($currency)),
                ];
            })
            ->all());
    }

    private function election(Subscription $subscription): string
    {
        if ($subscription->pending_plan_id !== null) {
            return 'successor';
        }

        return $subscription->cancel_at_period_end ? 'cancel' : 'none';
    }

    private function alreadyHandled(Subscription $subscription, DateTimeImmutable $retiresAt): bool
    {
        return $this->hasEvent($subscription, $retiresAt, PlanRetirementEvent::TYPE_MIGRATED)
            || $this->hasEvent($subscription, $retiresAt, PlanRetirementEvent::TYPE_UNRESOLVED);
    }

    private function reminded(Subscription $subscription, DateTimeImmutable $retiresAt): bool
    {
        return $this->hasEvent($subscription, $retiresAt, PlanRetirementEvent::TYPE_REMINDER);
    }

    private function hasEvent(Subscription $subscription, DateTimeImmutable $retiresAt, string $type): bool
    {
        return PlanRetirementEvent::query()
            ->where('subscription_id', $subscription->id)
            ->where('retires_at', Carbon::instance($retiresAt))
            ->where('type', $type)
            ->exists();
    }

    private function record(Subscription $subscription, Plan $plan, DateTimeImmutable $retiresAt, string $type, ?string $outcome, ?int $successorPlanId, string $detail): void
    {
        PlanRetirementEvent::query()->updateOrCreate(
            [
                'subscription_id' => $subscription->id,
                'retires_at' => Carbon::instance($retiresAt),
                'type' => $type,
            ],
            [
                'organization_id' => $subscription->organization_id,
                'plan_id' => $plan->id,
                'outcome' => $outcome,
                'successor_plan_id' => $successorPlanId,
                'detail' => $detail,
            ],
        );
    }

    private function planOf(Subscription $subscription): Plan
    {
        $plan = $subscription->plan;

        if (! $plan instanceof Plan) {
            throw new RuntimeException(sprintf('Subscription [%d] has no plan.', $subscription->id));
        }

        $plan->loadMissing(['prices', 'defaultSuccessor', 'product']);

        return $plan;
    }

    private function organizationOf(Subscription $subscription): Organization
    {
        $organization = $subscription->organization;

        if (! $organization instanceof Organization) {
            $organization = Organization::query()->findOrFail($subscription->organization_id);
        }

        return $organization;
    }

    private function nextPeriod(Subscription $subscription, Plan $plan): BillingPeriod
    {
        $cycle = SubscriptionPeriods::cycleFor($subscription, $plan, Carbon::now());
        $start = $this->toImmutable(SubscriptionPeriods::currentStart($subscription, Carbon::now()));

        return $cycle->nextPeriod($start);
    }

    private function label(DateTimeImmutable $at): string
    {
        return Carbon::instance($at)->format('j M Y');
    }

    private function toImmutable(Carbon $carbon): DateTimeImmutable
    {
        return $carbon->toDateTimeImmutable();
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
