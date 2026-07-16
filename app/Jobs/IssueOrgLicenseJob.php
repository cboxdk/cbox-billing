<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\Contracts\LicenseProfileResolver;
use Cbox\Billing\Licensing\SubscriptionLicensePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * (Re)issues the on-prem license for one active subscription on a licensable plan — the
 * per-tenant unit the `billing:issue-licenses` pass dispatches. Idempotent (one active
 * license per deployment): a deployment already covering the current paid period is skipped;
 * one whose license predates the period end is renewed. A non-licensable plan is a
 * deny-by-default no-op. Issuing/reissuing emails the customer their key (via the licensing
 * service). One org's failure retries in isolation.
 */
class IssueOrgLicenseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $subscriptionId) {}

    public function handle(
        IssuesLicenses $licenses,
        IssuedLicenseStore $store,
        LicenseProfileResolver $profiles,
        SubscriptionLicensePolicy $policy,
    ): void {
        $subscription = Subscription::query()
            ->with(['organization', 'plan'])
            ->find($this->subscriptionId);

        if (! $subscription instanceof Subscription || $subscription->status->value !== 'active' || $subscription->isPaused()) {
            return;
        }

        $plan = $subscription->plan;

        if (! $plan instanceof Plan || $profiles->resolve($plan->key) === null) {
            return; // Not a licensable plan — deny-by-default.
        }

        $deploymentId = 'dep_'.$subscription->organization_id;
        $periodEnd = $subscription->current_period_end ?? Carbon::now()->endOfMonth();
        $expiresAt = $policy->expiresAtFor($periodEnd->toDateTimeImmutable());

        $existing = $store->forDeployment($deploymentId);

        if ($existing === null) {
            $licenses->issue(
                customerId: $subscription->organization_id,
                planId: $plan->key,
                deploymentId: $deploymentId,
                expiresAt: $expiresAt,
            );

            return;
        }

        if ($existing->expiresAt >= $expiresAt && $existing->plan === $plan->key) {
            return; // Already current — nothing to do.
        }

        $licenses->renew($existing->id, $expiresAt);
    }
}
