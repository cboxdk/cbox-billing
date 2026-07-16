<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use Illuminate\Console\Command;

/**
 * Enacts every subscription plan change that was scheduled for its period end and has now
 * come due (ADR-0012). A deferred change is stored on the subscription and surfaced
 * distinctly from an immediate one; this pass is what applies it at renewal — a thin
 * adapter over the depth service, which runs the same gated plan-change flow an immediate
 * change does.
 */
class ApplyScheduledChanges extends Command
{
    protected $signature = 'billing:apply-scheduled-changes';

    protected $description = 'Apply subscription plan changes scheduled for the period end that have come due (ADR-0012).';

    public function handle(ManagesSubscriptionDepth $depth): int
    {
        $applied = $depth->applyDueScheduledChanges();

        $this->info(sprintf('Applied %d scheduled subscription change%s.', $applied, $applied === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
