<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Nexus\NexusReporter;
use Cbox\Nexus\ValueObjects\NexusEvaluation;
use Illuminate\Console\Command;

/**
 * Reports the default selling entity's US economic-nexus standing across the states
 * it sells into: where an obligation has been triggered (act now) and where it is
 * approaching (watch). Registrations are counted, not listed — a handled state needs
 * no action. Thresholds come from the us-tax-data dataset; sales from this app's
 * invoices. Run on a schedule to feed a registration watchlist.
 */
class ReportNexus extends Command
{
    protected $signature = 'nexus:report';

    protected $description = 'Report US economic-nexus status for the default seller across the states it sells into.';

    public function handle(NexusReporter $reporter): int
    {
        $report = $reporter->report();
        $triggered = $report->triggered();
        $approaching = $report->approaching();

        if ($triggered === [] && $approaching === []) {
            $this->info('No US states have triggered or are approaching economic nexus.');

            return self::SUCCESS;
        }

        $this->table(
            ['State', 'Status', 'Threshold', 'Progress', 'Reason'],
            array_map(static fn (NexusEvaluation $e): array => [
                $e->state->value,
                $e->status->value,
                $e->threshold?->describe() ?? '—',
                $e->progress !== null ? number_format($e->progress * 100, 1).'%' : '—',
                $e->reason,
            ], [...$triggered, ...$approaching]),
        );

        $this->info(sprintf(
            '%d triggered, %d approaching, %d already registered.',
            count($triggered),
            count($approaching),
            count($report->registered()),
        ));

        return self::SUCCESS;
    }
}
