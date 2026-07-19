<?php

declare(strict_types=1);

namespace App\Billing\Audit\Support;

use App\Billing\Audit\AuditRecorder;

/**
 * A per-request counter of how many audit events have been recorded, shared (as a singleton)
 * between the {@see AuditRecorder} and the recording middleware. The
 * middleware resets it at the start of a request and, after the controller runs, records a
 * fallback event only when the count is still zero — so an explicitly-instrumented mutation
 * logs exactly one (rich) event while an un-instrumented one is still covered by the fallback.
 */
class AuditRequestTally
{
    private int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}
