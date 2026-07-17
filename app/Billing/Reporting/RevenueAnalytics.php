<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Support\SubscriptionRevenue;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ChurnCalculator;
use Cbox\Billing\Reporting\CohortRetention;
use Cbox\Billing\Reporting\MrrCalculator;
use Cbox\Billing\Reporting\MrrMovement;
use Cbox\Billing\Reporting\RetentionCalculator;
use Cbox\Billing\Reporting\ValueObjects\ArrWaterfall;
use Cbox\Billing\Reporting\ValueObjects\CohortMatrix;
use Cbox\Billing\Reporting\ValueObjects\MrrMovementReport;
use Cbox\Billing\Reporting\ValueObjects\RetentionRates;
use Cbox\Billing\Reporting\ValueObjects\RevenueReport;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionMovement;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionMrr;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionPeriodMrr;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The analytics read model: it assembles the engine Reporting module's inputs from real
 * {@see Subscription} rows and hands them to the engine calculators, so every headline —
 * MRR/ARR, the MRR-movement waterfall, NRR/GRR and the cohort matrix — is computed by the
 * engine, never hand-derived.
 *
 * A subscription's monthly-recurring amount is its plan's normalised monthly price
 * ({@see SubscriptionRevenue}); the engine's state→MRR policy decides which lifecycle
 * states contribute. Movement across a window is reconstructed from each subscription's
 * contributing amount at the window's two edges — derived from its lifecycle timestamps
 * (`created_at`, `canceled_at`, `paused_at`, `trial_ends_at`) — and a `returning` signal
 * read from the append-only reactivation log, so new logos, churn and win-backs are real
 * events rather than snapshots the base app does not yet store. Expansion/contraction are
 * zero unless a subscription's amount differs between the edges (no plan-change history is
 * recorded yet), and the waterfall still reconciles exactly by construction.
 */
readonly class RevenueAnalytics
{
    public function __construct(
        private MrrCalculator $mrr,
        private MrrMovement $movement,
        private RetentionCalculator $retention,
        private CohortRetention $cohortRetention,
        private ChurnCalculator $churn,
    ) {}

    public function primaryCurrency(): string
    {
        $default = config('billing.default_currency');

        return is_string($default) ? $default : 'DKK';
    }

    /** MRR/ARR per currency, summed by the engine under its state→MRR policy over every subscription. */
    public function revenue(): RevenueReport
    {
        $inputs = [];

        foreach ($this->subscriptions() as $subscription) {
            $inputs[] = new SubscriptionMrr(
                $this->effectiveStatus($subscription),
                SubscriptionRevenue::monthly($subscription),
            );
        }

        return $this->mrr->summarizeSubscriptions($inputs);
    }

    /**
     * The MRR-movement decomposition (new / expansion / contraction / churn / reactivation)
     * per currency between `$start` and `$end`, computed by the engine from each
     * subscription's contributing amount at the two edges.
     */
    public function movement(Carbon $start, Carbon $end): MrrMovementReport
    {
        $reactivatedOrgs = $this->reactivatedOrganizations();
        $movements = [];

        foreach ($this->subscriptions() as $subscription) {
            $startMrr = $this->mrrAt($subscription, $start);
            $endMrr = $this->mrrAt($subscription, $end);

            if ($startMrr->isZero() && $endMrr->isZero()) {
                continue;
            }

            $movements[] = new SubscriptionMovement(
                (string) $subscription->id,
                $startMrr,
                $endMrr,
                returning: in_array($subscription->organization_id, $reactivatedOrgs, true),
            );
        }

        return $this->movement->waterfall($movements);
    }

    /** The ARR bridge for `$currency` over the window, or null when that currency has no movement. */
    public function arr(Carbon $start, Carbon $end, string $currency): ?ArrWaterfall
    {
        $waterfall = $this->movement($start, $end)->waterfallFor($currency);

        return $waterfall?->toArr();
    }

    /** Net/gross revenue retention for `$currency` over the window, from the movement waterfall. */
    public function retention(Carbon $start, Carbon $end, string $currency): ?RetentionRates
    {
        $waterfall = $this->movement($start, $end)->waterfallFor($currency);

        return $waterfall === null ? null : $this->retention->fromWaterfall($waterfall);
    }

    /**
     * Customer (logo) churn over the window: the fraction of subscriptions present at
     * `$start` that were canceled by `$end`.
     */
    public function customerChurn(Carbon $start, Carbon $end): float
    {
        $atStart = 0;
        $churned = 0;

        foreach ($this->subscriptions() as $subscription) {
            $existed = $subscription->created_at !== null && $subscription->created_at->lessThanOrEqualTo($start);
            $canceledInWindow = $subscription->canceled_at !== null
                && $subscription->canceled_at->greaterThan($start)
                && $subscription->canceled_at->lessThanOrEqualTo($end);

            if ($existed) {
                $atStart++;
            }

            if ($existed && $canceledInWindow) {
                $churned++;
            }
        }

        return $this->churn->rate($atStart, $churned);
    }

    /**
     * A cohort × age retention matrix over the given ordered month labels (`YYYY-MM`),
     * built by the engine from each primary-currency subscription's MRR at each month end.
     * Subscriptions started before the earliest label belong to an off-matrix cohort and
     * are excluded (the standard "last N cohorts" view).
     *
     * @param  list<string>  $periods
     */
    public function cohorts(array $periods): CohortMatrix
    {
        if ($periods === []) {
            return new CohortMatrix([], []);
        }

        $currency = $this->primaryCurrency();
        $ends = [];

        foreach ($periods as $label) {
            $ends[$label] = Carbon::parse($label.'-01')->endOfMonth();
        }

        $index = array_flip($periods);
        $rows = [];

        foreach ($this->subscriptions() as $subscription) {
            if (SubscriptionRevenue::currency($subscription) !== $currency) {
                continue;
            }

            $cohort = $subscription->created_at?->format('Y-m');

            if ($cohort === null || ! isset($index[$cohort])) {
                continue;
            }

            $series = [];

            foreach ($periods as $label) {
                $series[] = $this->mrrAt($subscription, $ends[$label]);
            }

            $rows[] = new SubscriptionPeriodMrr((string) $subscription->id, $cohort, $series);
        }

        return $this->cohortRetention->matrix($periods, $rows);
    }

    /**
     * The last `$count` month labels (`YYYY-MM`) ending with `$end`'s month, oldest first —
     * the period axis a cohort matrix is defined over.
     *
     * @return list<string>
     */
    public function monthLabels(int $count, Carbon $end): array
    {
        $labels = [];

        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $labels[] = $end->copy()->startOfMonth()->subMonthsNoOverflow($offset)->format('Y-m');
        }

        return $labels;
    }

    /**
     * The subscription's monthly-recurring amount as of `$at`, or zero when it was not
     * contributing then — i.e. it did not yet exist, was already canceled or paused, or was
     * still within its trial. Mirrors the engine's state→MRR policy, applied historically.
     */
    private function mrrAt(Subscription $subscription, Carbon $at): Money
    {
        $monthly = SubscriptionRevenue::monthly($subscription);
        $zero = Money::zero($monthly->currency());

        if ($subscription->created_at === null || $subscription->created_at->greaterThan($at)) {
            return $zero;
        }

        if ($subscription->canceled_at !== null && $subscription->canceled_at->lessThanOrEqualTo($at)) {
            return $zero;
        }

        if ($subscription->paused_at !== null && $subscription->paused_at->lessThanOrEqualTo($at)) {
            return $zero;
        }

        if ($subscription->trial_ends_at !== null && $subscription->trial_ends_at->greaterThan($at)) {
            return $zero;
        }

        return $monthly;
    }

    /**
     * The subscription's effective lifecycle state for the current-MRR policy: a paused
     * subscription reads as {@see SubscriptionStatus::Paused} even when its stored engine
     * status is still Active, so suspended billing contributes nothing.
     */
    private function effectiveStatus(Subscription $subscription): SubscriptionStatus
    {
        return $subscription->isPaused() ? SubscriptionStatus::Paused : $subscription->status;
    }

    /**
     * The organizations that have a recorded win-back reactivation — the `returning` signal
     * that classifies a zero→positive movement as reactivation rather than a new logo.
     *
     * @return list<string>
     */
    private function reactivatedOrganizations(): array
    {
        $orgs = [];

        foreach (SubscriptionCancellation::query()
            ->where('mode', SubscriptionCancellation::MODE_REACTIVATE)
            ->distinct()
            ->pluck('organization_id') as $organizationId) {
            if (is_string($organizationId)) {
                $orgs[] = $organizationId;
            }
        }

        return $orgs;
    }

    /** @return Collection<int, Subscription> */
    private function subscriptions(): Collection
    {
        return Subscription::query()
            ->with(['organization', 'plan.prices'])
            ->get();
    }
}
