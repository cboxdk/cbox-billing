<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Support\Initials;
use App\Billing\Support\SubscriptionRevenue;
use App\Billing\Support\SubscriptionStanding;
use App\Models\PaymentRetry;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use Illuminate\Support\Collection;

/**
 * Read model for the Subscriptions screens. Projects real {@see Subscription} rows into
 * the flat shape the table renders, with MRR from the engine money value object and the
 * console display standing derived by {@see SubscriptionStanding}. URL-is-state: the
 * optional status filter narrows the list to a single display standing.
 */
readonly class SubscriptionReport
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(?string $status = null): Collection
    {
        $rows = Subscription::query()
            ->with(['organization.invoices', 'plan.prices'])
            ->get()
            ->map(fn (Subscription $subscription): array => $this->row($subscription))
            ->sortBy('org')
            ->values();

        if ($status !== null && $status !== 'all') {
            return $rows->where('status', $status)->values();
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $subscription = Subscription::query()
            ->with(['organization.invoices', 'plan.prices', 'plan.entitlements.meter', 'plan.creditGrants'])
            ->find($id);

        if ($subscription === null) {
            return null;
        }

        $row = $this->row($subscription);
        $plan = $subscription->plan;

        $row['seats'] = $subscription->seats;
        $row['interval'] = 'month';
        $row['period_start'] = $subscription->current_period_start?->format('Y-m-d') ?? '—';
        $row['period_end'] = $subscription->current_period_end?->format('Y-m-d') ?? '—';
        $row['cancel_at_period_end'] = $subscription->cancel_at_period_end;
        $row['reactivatable'] = $subscription->isPaused()
            || ($subscription->cancel_at_period_end && ! $subscription->isCanceled())
            || $subscription->isCanceled();
        $row['dunning'] = $this->dunningFor($subscription);
        $row['cancellations'] = $this->cancellationsFor($subscription->organization_id);
        $row['credits'] = [];
        $row['entitlements'] = [];

        if ($plan === null) {
            return $row;
        }

        $row['interval'] = $plan->interval;
        $row['credits'] = $plan->creditGrants
            ->map(static fn (PlanCreditGrant $grant): array => [
                'pool' => $grant->pool,
                'amount' => $grant->amount,
                'cadence' => $grant->cadence->value,
                'denomination' => $grant->denomination,
            ])->all();
        $row['entitlements'] = $plan->entitlements
            ->map(static function (PlanEntitlement $entitlement): array {
                $meter = $entitlement->meter;

                return [
                    'meter' => $meter !== null ? $meter->name : '—',
                    'unit' => $meter !== null ? $meter->unit : '',
                    'enabled' => $entitlement->enabled,
                    'unlimited' => $entitlement->unlimited,
                    'allowance' => $entitlement->allowance,
                    'overage' => $entitlement->overage->value,
                ];
            })->all();

        return $row;
    }

    /**
     * @return array{active: int, trialing: int, past_due: int, paused: int, non_renewing: int, canceled: int, all: int}
     */
    public function counts(): array
    {
        return SubscriptionStanding::counts();
    }

    /**
     * The dunning view: every subscription under active smart-retry (a `retrying`
     * {@see PaymentRetry}), newest failure first — attempts, next retry and status.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dunning(): Collection
    {
        return PaymentRetry::query()
            ->with(['subscription.organization', 'invoice'])
            ->orderByRaw("CASE status WHEN 'retrying' THEN 0 WHEN 'exhausted' THEN 1 ELSE 2 END")
            ->orderByDesc('first_failed_at')
            ->get()
            ->map(fn (PaymentRetry $retry): array => $this->dunningRow($retry));
    }

    /**
     * One dunning-list row for a smart-retry.
     *
     * @return array<string, mixed>
     */
    private function dunningRow(PaymentRetry $retry): array
    {
        $organization = $retry->subscription?->organization;
        $name = $organization !== null ? $organization->name : $retry->organization_id;
        $invoice = $retry->invoice;

        return [
            'org' => $name,
            'ini' => Initials::of($name),
            'subscription_id' => $retry->subscription_id,
            'invoice' => $invoice !== null ? $invoice->number : '—',
            'invoice_minor' => $invoice !== null ? $invoice->total_minor : 0,
            'currency' => $invoice !== null ? $invoice->currency : 'DKK',
            'attempts' => $retry->attempts,
            'max_attempts' => $retry->max_attempts,
            'status' => $retry->status,
            'first_failed_at' => $retry->first_failed_at->format('Y-m-d'),
            'next_attempt_at' => $retry->next_attempt_at?->format('Y-m-d') ?? '—',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Subscription $subscription): array
    {
        $monthly = SubscriptionRevenue::monthly($subscription);
        $organization = $subscription->organization;
        $plan = $subscription->plan;
        $name = $organization !== null ? $organization->name : 'Unknown';

        $standing = SubscriptionStanding::of($subscription);

        return [
            'id' => $subscription->id,
            'org' => $name,
            'org_id' => $organization !== null ? $organization->id : '',
            'ini' => Initials::of($name),
            'plan' => $plan !== null ? $plan->name : '—',
            'plan_key' => $plan !== null ? $plan->key : '',
            'minor' => $monthly->minor(),
            'currency' => $monthly->currency(),
            'status' => $standing,
            'seats' => $subscription->seats,
            'started' => $subscription->created_at?->format('Y-m-d') ?? '—',
            'trial_ends' => $subscription->trial_ends_at?->format('Y-m-d'),
            'paused_at' => $subscription->paused_at?->format('Y-m-d'),
            'renews' => $this->renews($subscription, $standing),
        ];
    }

    /** The renewal cell: trial end, scheduled-cancel date, paused, or the next period end. */
    private function renews(Subscription $subscription, string $standing): string
    {
        if ($standing === SubscriptionStanding::PAUSED) {
            return 'paused '.($subscription->paused_at?->format('Y-m-d') ?? '');
        }

        if ($standing === SubscriptionStanding::TRIALING && $subscription->trial_ends_at !== null) {
            return 'trial ends '.$subscription->trial_ends_at->format('Y-m-d');
        }

        if ($subscription->cancel_at_period_end) {
            return 'cancels '.($subscription->current_period_end?->format('Y-m-d') ?? '—');
        }

        return $subscription->current_period_end?->format('Y-m-d') ?? '—';
    }

    /**
     * The active smart-retry state for a subscription's failed charge, or null when it is
     * not in dunning.
     *
     * @return array{attempts: int, max_attempts: int, status: string, next_attempt_at: string, first_failed_at: string, invoice: string}|null
     */
    private function dunningFor(Subscription $subscription): ?array
    {
        $retry = PaymentRetry::query()
            ->with('invoice')
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('first_failed_at')
            ->first();

        if (! $retry instanceof PaymentRetry) {
            return null;
        }

        $invoice = $retry->invoice;

        return [
            'attempts' => $retry->attempts,
            'max_attempts' => $retry->max_attempts,
            'status' => $retry->status,
            'next_attempt_at' => $retry->next_attempt_at?->format('Y-m-d') ?? '—',
            'first_failed_at' => $retry->first_failed_at->format('Y-m-d'),
            'invoice' => $invoice !== null ? $invoice->number : '—',
        ];
    }

    /**
     * The captured retention events for an organization (append-only churn log), newest
     * first — the reasons customers gave when canceling, pausing, or being won back.
     *
     * @return list<array{mode: string, reason: string|null, feedback: string|null, at: string}>
     */
    private function cancellationsFor(string $organizationId): array
    {
        return array_values(SubscriptionCancellation::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (SubscriptionCancellation $cancellation): array => [
                'mode' => $cancellation->mode,
                'reason' => $cancellation->reason,
                'feedback' => $cancellation->feedback,
                'at' => $cancellation->created_at?->format('Y-m-d') ?? '—',
            ])
            ->all());
    }
}
