<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Support\Initials;
use App\Billing\Support\SubscriptionRevenue;
use App\Billing\Support\SubscriptionStanding;
use App\Models\PlanCreditGrant;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
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
     * @return array{active: int, trialing: int, past_due: int, canceled: int, all: int}
     */
    public function counts(): array
    {
        return SubscriptionStanding::counts();
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

        return [
            'id' => $subscription->id,
            'org' => $name,
            'org_id' => $organization !== null ? $organization->id : '',
            'ini' => Initials::of($name),
            'plan' => $plan !== null ? $plan->name : '—',
            'plan_key' => $plan !== null ? $plan->key : '',
            'minor' => $monthly->minor(),
            'currency' => $monthly->currency(),
            'status' => SubscriptionStanding::of($subscription),
            'seats' => $subscription->seats,
            'started' => $subscription->created_at?->format('Y-m-d') ?? '—',
            'renews' => $subscription->cancel_at_period_end
                ? 'cancels '.($subscription->current_period_end?->format('Y-m-d') ?? '—')
                : ($subscription->current_period_end?->format('Y-m-d') ?? '—'),
        ];
    }
}
