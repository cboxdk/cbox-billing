<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Issues one active subscription's period invoice. The per-tenant unit the `billing:invoice`
 * pass dispatches: isolating each subscription in its own queued job means a single org's
 * failure retries on its own without stalling or aborting the whole monthly run.
 *
 * A tax-pending org (no resolvable billing address) is a permanent skip for this cycle — it
 * is logged, not retried — while an infrastructure error propagates so the queue retries it.
 */
class IssueSubscriptionInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $subscriptionId) {}

    public function handle(GeneratesInvoices $invoices, LoggerInterface $log): void
    {
        $subscription = Subscription::query()
            ->with(['organization', 'plan'])
            ->find($this->subscriptionId);

        if (! $subscription instanceof Subscription || $subscription->status->value !== 'active') {
            return;
        }

        try {
            $invoices->generate($subscription);
        } catch (RuntimeException $e) {
            // Tax-pending (or another domain precondition) — not a retryable fault. The
            // monthly pass will catch it once the org's billing address is set.
            $log->info('Skipped invoice: '.$e->getMessage(), [
                'subscription' => $subscription->id,
                'organization' => $subscription->organization_id,
            ]);
        }
    }
}
