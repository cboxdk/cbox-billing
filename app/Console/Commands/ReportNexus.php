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

        // Multi-channel caveat: the sales counted here are only those invoiced through
        // this platform. Other channels also count toward each state's threshold, so a
        // Below/Approaching state may already be Triggered once combined.
        if (! $reporter->soleSalesChannel()) {
            $this->warn('Sales reflect only invoices issued through this platform. Sales through other channels');
            $this->warn('(marketplaces, other systems) also count toward each state threshold and are not included —');
            $this->warn('a state shown Below or Approaching may already be Triggered once all channels are combined.');
            $this->newLine();
        }

        // UNKNOWN IS NOT QUIET. A state whose threshold could not be resolved is neither
        // triggered nor approaching, so a sweep reading only those two prints "nothing to do"
        // for a deployment where NOTHING was measured — a firewalled or misconfigured dataset
        // location resolves every state to unknown. The alert emitter and the console both
        // surface it; this scheduled command was the last place still rendering "I cannot tell"
        // as "you are fine", and it is the one an operator reads in a cron log.
        $unknown = $report->unknown();

        if ($unknown !== []) {
            $this->warn(sprintf(
                '%d state(s) could not be measured — their thresholds did not resolve. This is NOT "below": %s',
                count($unknown),
                implode(', ', array_map(static fn ($evaluation): string => $evaluation->state->value, $unknown)),
            ));
            $this->warn('Check NEXUS_US_DATASET_LOCATION and that the deployment can reach it.');
            $this->newLine();
        }

        if ($triggered === [] && $approaching === []) {
            $this->info("No US states have triggered or are approaching economic nexus (on this platform's sales).");

            // A board that resolved nothing is not a clean board. Exit non-zero so a scheduled
            // run surfaces in whatever watches the cron, rather than reading as success.
            return $unknown === [] ? self::SUCCESS : self::FAILURE;
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
