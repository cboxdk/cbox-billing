<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Meter;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Reconciliation\Contracts\Reconciler;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Reconciles the durable usage into the ledger for one organization's entitled meters
 * (ADR-0003) — the per-tenant unit the `billing:reconcile-active` pass dispatches. Each org
 * runs in its own queued job so a store/ledger hiccup for one org retries on its own rather
 * than failing the whole convergence pass. Deny-by-default: a meter with no resolved policy
 * for the org is not a reconcile target.
 */
class ReconcileOrgUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $organizationId) {}

    public function handle(Reconciler $reconciler, MeterPolicyResolver $policies, LoggerInterface $log): void
    {
        $targets = $this->targets($policies);

        if ($targets === []) {
            return;
        }

        $report = $reconciler->reconcile($targets);

        foreach ($report->skipped as $failure) {
            $log->warning('Reconcile skipped a target.', [
                'organization' => $failure->target->org,
                'meter' => $failure->target->meter,
                'error' => $failure->error->getMessage(),
            ]);
        }
    }

    /**
     * The org's meters that resolve to a policy — the reconcile targets.
     *
     * @return list<ReconcileTarget>
     */
    private function targets(MeterPolicyResolver $policies): array
    {
        $targets = [];

        foreach (Meter::query()->orderBy('key')->pluck('key') as $meter) {
            if (is_string($meter) && $policies->resolve($this->organizationId, $meter) !== null) {
                $targets[] = new ReconcileTarget($this->organizationId, $meter);
            }
        }

        return $targets;
    }
}
