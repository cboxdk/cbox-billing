<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Support\SubscriptionStanding;
use Illuminate\Console\Command;

/**
 * The daily catch-up for the materialized console display standing (PERF-3). Most standing
 * changes are event-driven (a subscription lifecycle write, an invoice status change), but the
 * overdue-open-invoice fallback turns on when an invoice merely CROSSES its due date — a
 * passage of time with no write to observe. This pass recomputes every subscription's standing
 * so a newly-overdue account is reflected without waiting for its next write.
 */
class RefreshSubscriptionStandings extends Command
{
    protected $signature = 'billing:refresh-standings';

    protected $description = 'Recompute the materialized subscription display standings (catches due-date crossings).';

    public function handle(): int
    {
        SubscriptionStanding::refreshAll();

        $this->info('Subscription display standings refreshed.');

        return self::SUCCESS;
    }
}
