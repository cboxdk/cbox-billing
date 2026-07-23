<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

use App\Billing\Seller\SellerCatalog;
use App\Mail\NexusAlertMail;
use App\Models\NexusAlertDispatch;
use Cbox\Nexus\ValueObjects\NexusEvaluation;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Surfaces the economic-nexus alert: it runs the {@see NexusReporter} for the default seller
 * and, for each state now Approaching (watch) or Triggered (act — register), records the
 * crossing to the {@see NexusAlertDispatch} ledger. The ledger's unique key makes a crossing
 * surface exactly once per measurement period even though the sweep runs on a schedule, and
 * feeds the console watchlist ("new since you last looked").
 *
 * Delivery: when operator recipients are configured ({@see Config} `billing.nexus.alerts.recipients`)
 * the newly-crossed states are emailed to them; with none configured the crossing is still
 * recorded (the console shows it) but no email is sent — the alert is never lost, and no
 * fictitious mailbox is invented.
 */
readonly class NexusAlertEmitter
{
    public function __construct(
        private NexusReporter $reporter,
        private SellerCatalog $sellers,
        private Config $config,
        private Mailer $mailer,
    ) {}

    /**
     * Evaluate the default seller's exposure and record any newly-crossed state. Returns the
     * evaluations recorded this run (the ones an operator has not yet been alerted to).
     *
     * @return list<NexusEvaluation>
     */
    public function sweep(): array
    {
        if ($this->config->get('billing.nexus.alerts.enabled', true) === false) {
            return [];
        }

        $report = $this->reporter->report();
        $sellerId = $this->sellers->default()->id;
        $periodKey = (string) Carbon::now()->year;

        $newly = [];

        // Triggered first (act now), then Approaching (watch) — a state is only ever in one.
        foreach ([...$report->triggered(), ...$report->approaching()] as $evaluation) {
            if ($this->recordOnce($sellerId, $evaluation->state->value, $periodKey, $evaluation->status->value)) {
                $newly[] = $evaluation;
            }
        }

        if ($newly !== []) {
            $this->deliver($newly);
        }

        return $newly;
    }

    /**
     * Record that the alert for (seller, state, period, status) has fired. Returns true the
     * first time (a new row), false when already recorded — the unique key is the
     * concurrency-safe guard, so two concurrent sweeps still surface a crossing once.
     */
    private function recordOnce(string $sellerId, string $subdivision, string $periodKey, string $status): bool
    {
        try {
            NexusAlertDispatch::query()->create([
                'seller_entity_id' => $sellerId,
                'subdivision' => $subdivision,
                'period_key' => $periodKey,
                'status' => $status,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Already surfaced this period — only a uniqueness violation is swallowed; any other
            // query failure propagates rather than being silently treated as "already sent".
            return false;
        }
    }

    /**
     * Email the newly-crossed states to the configured operator recipients. A no-op (by design)
     * when none are configured — the crossing is already recorded for the console.
     *
     * @param  list<NexusEvaluation>  $newly
     */
    private function deliver(array $newly): void
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        $this->mailer->to($recipients)->send(new NexusAlertMail(
            array_map(static fn (NexusEvaluation $e): array => [
                'state' => $e->state->value,
                'status' => ucfirst($e->status->value),
                'threshold' => $e->threshold?->describe() ?? '—',
                'progress' => $e->progress !== null ? number_format($e->progress * 100, 1).'%' : '—',
                'reason' => $e->reason,
            ], $newly),
            $this->reporter->soleSalesChannel(),
        ));
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $configured = $this->config->get('billing.nexus.alerts.recipients', []);

        return is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
    }
}
