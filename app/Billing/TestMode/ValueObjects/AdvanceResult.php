<?php

declare(strict_types=1);

namespace App\Billing\TestMode\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * What a test-clock advance did: where it moved the virtual clock from/to, and the counts of
 * the due billing logic it ran (renewals fired, trials converted, dunning attempts, invoices
 * raised) — the audit an integrator reads back to confirm a simulated year behaved.
 */
readonly class AdvanceResult
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $renewals,
        public int $trialConversions,
        public int $dunningAttempts,
        public int $invoices,
        public int $steps,
    ) {}
}
