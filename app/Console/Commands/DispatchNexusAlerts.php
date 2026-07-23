<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Nexus\NexusAlertEmitter;
use Illuminate\Console\Command;

/**
 * The scheduled economic-nexus alert sweep: evaluate the default seller's exposure across the
 * states it sells into and record any newly Approaching/Triggered crossing, emailing the
 * configured operations recipients. Idempotent per (seller, state, period, status), so running
 * it daily surfaces each crossing exactly once per measurement period.
 */
class DispatchNexusAlerts extends Command
{
    protected $signature = 'nexus:alerts';

    protected $description = 'Record and notify newly Approaching/Triggered US economic-nexus states for the default seller.';

    public function handle(NexusAlertEmitter $emitter): int
    {
        $newly = $emitter->sweep();
        $count = count($newly);

        $this->info(sprintf('Recorded %d new nexus %s.', $count, $count === 1 ? 'alert' : 'alerts'));

        return self::SUCCESS;
    }
}
