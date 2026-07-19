<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Providers\WebhookServiceProvider;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Console\Command;

/**
 * The outbound-webhook retry sweep — re-attempts failed deliveries whose exponential backoff is
 * due. Wired onto the scheduler every minute by {@see WebhookServiceProvider}; also
 * runnable by hand. Idempotent: a delivery already delivered is skipped, and a still-failing one
 * simply reschedules (or dead-letters at the budget).
 */
class RetryPendingWebhooks extends Command
{
    protected $signature = 'webhooks:retry-pending {--limit=100 : Maximum due deliveries to sweep in one pass}';

    protected $description = 'Re-attempt failed outbound webhook deliveries whose retry backoff is due.';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $limit = (int) $this->option('limit');
        $count = $dispatcher->retryPending($limit > 0 ? $limit : 100);

        $this->info(sprintf('Swept %d due webhook %s.', $count, $count === 1 ? 'delivery' : 'deliveries'));

        return self::SUCCESS;
    }
}
