<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\Contracts\LicenseProfileResolver;
use Cbox\Billing\Licensing\SubscriptionLicensePolicy;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * (Re)issues on-prem licenses for active subscriptions on a licensable plan. This is the
 * explicit, idempotent path that wires issuance into the subscription lifecycle: a
 * subscription whose plan resolves to a license profile is minted a license bound to a
 * deterministic per-organization deployment id, with the expiry tracking the paid period
 * (period end + the configured grace) via the engine's {@see SubscriptionLicensePolicy}.
 *
 * Idempotent by design — one active license per deployment: a deployment that already
 * holds a license covering the current paid period is skipped; one whose license predates
 * the current period end is renewed (reissued under a fresh id, extended window). A
 * non-licensable plan is silently skipped (deny-by-default). `--org=` limits the run.
 */
class IssueLicenses extends Command
{
    protected $signature = 'billing:issue-licenses {--org= : Limit the run to one organization id}';

    protected $description = 'Mint or renew on-prem licenses for active subscriptions on a licensable plan (idempotent).';

    public function handle(
        IssuesLicenses $licenses,
        IssuedLicenseStore $store,
        LicenseProfileResolver $profiles,
        SubscriptionLicensePolicy $policy,
    ): int {
        $query = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNull('paused_at')
            ->with(['organization', 'plan']);

        $org = $this->option('org');

        if (is_string($org) && $org !== '') {
            $query->where('organization_id', $org);
        }

        $issued = 0;
        $renewed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($query->get() as $subscription) {
            $plan = $subscription->plan;

            if (! $plan instanceof Plan || $profiles->resolve($plan->key) === null) {
                continue; // Not a licensable plan — deny-by-default.
            }

            $deploymentId = 'dep_'.$subscription->organization_id;
            $periodEnd = $subscription->current_period_end ?? Carbon::now()->endOfMonth();
            $expiresAt = $policy->expiresAtFor($periodEnd->toDateTimeImmutable());

            try {
                $existing = $store->forDeployment($deploymentId);

                if ($existing === null) {
                    $licenses->issue(
                        customerId: $subscription->organization_id,
                        planId: $plan->key,
                        deploymentId: $deploymentId,
                        expiresAt: $expiresAt,
                    );
                    $issued++;
                    $this->line(sprintf('<info>%s</info> issued (%s → %s)', $subscription->organization_id, $plan->key, $expiresAt->format('Y-m-d')));

                    continue;
                }

                if ($existing->expiresAt >= $expiresAt && $existing->plan === $plan->key) {
                    $skipped++;

                    continue; // Already current — nothing to do.
                }

                $licenses->renew($existing->id, $expiresAt);
                $renewed++;
                $this->line(sprintf('<info>%s</info> renewed to %s', $subscription->organization_id, $expiresAt->format('Y-m-d')));
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('%s failed: %s', $subscription->organization_id, $e->getMessage()));
            }
        }

        $this->info(sprintf('Issued %d, renewed %d, skipped %d, %d failed.', $issued, $renewed, $skipped, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
