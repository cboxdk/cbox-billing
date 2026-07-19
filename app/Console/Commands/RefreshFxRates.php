<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Fx\FxRateRefresher;
use Illuminate\Console\Command;

/**
 * The scheduled FX-rate pull: fetch the current ECB euro reference rates and persist any
 * operator override rates into `fx_rates`, upserting so a re-run refreshes rather than
 * duplicates. A thin adapter over {@see FxRateRefresher}. Fails (non-zero) if any source
 * errored, but a partial success (e.g. ECB down, overrides still written) is reported per
 * source and never blocks the others.
 */
class RefreshFxRates extends Command
{
    protected $signature = 'fx:refresh';

    protected $description = 'Pull ECB euro reference rates (and operator overrides) into the fx_rates store for consolidated reporting.';

    public function handle(FxRateRefresher $refresher): int
    {
        $failures = 0;

        foreach ($refresher->refresh() as $result) {
            if ($result->ok) {
                $this->line(sprintf('[%s] %d rate(s) persisted.', $result->origin->label(), $result->count));

                continue;
            }

            $failures++;
            $this->error(sprintf('[%s] refresh failed: %s', $result->origin->label(), (string) $result->error));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
