<?php

declare(strict_types=1);

namespace App\Billing\Mode\Contracts;

use Carbon\CarbonImmutable;

/**
 * The seam every time-sensitive billing path reads "now" through, instead of calling
 * {@see CarbonImmutable::now()} directly. In LIVE mode it returns the real clock; while a
 * test clock is being advanced it returns that clock's virtual time, so a renewal, a trial
 * conversion or a dunning attempt fires exactly as it would after real elapsed time — but in
 * seconds and deterministically.
 *
 * Coverage boundary (documented, deliberate): the seam is read only by the billing
 * DECISION paths a test clock drives — renewal, trial conversion, dunning cadence,
 * retirement cutoffs, and subscription anchoring. Incidental `now()` reads (audit stamps,
 * `last_used_at`, archived-at, ledger event timestamps, reporting windows) still use the
 * system clock; they are not time-sensitive to a virtual clock and are intentionally left
 * out of the seam. See docs/testing/test-clock.md for the exact list.
 */
interface BillingClock
{
    /** The current instant — real now in live mode, the virtual now while a test clock advances. */
    public function now(): CarbonImmutable;
}
