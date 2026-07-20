<?php

declare(strict_types=1);

namespace App\Billing\Mode;

use App\Billing\Mode\Contracts\BillingClock;
use App\Billing\TestMode\TestClockAdvancer;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The one ambient holder of the request's (or CLI pass's) billing MODE and, while a test
 * clock is being advanced, its virtual "now". Bound as a singleton and as the app's
 * {@see BillingClock}, so:
 *
 *  - the {@see LivemodeScope} on every partitioned model reads {@see livemode()} to filter
 *    to the current plane (deny-by-default: live unless a test credential set otherwise);
 *  - every time-sensitive billing service reads {@see now()} for the current instant.
 *
 * It starts LIVE with no virtual time, so nothing outside test mode changes: `now()` is real
 * now and the scope selects `livemode = true`. The console middleware / API authenticator set
 * the mode from the credential; the {@see TestClockAdvancer} scopes a
 * virtual-time window around the due-logic run with {@see runAtVirtualTime()}.
 */
class BillingContext implements BillingClock
{
    private BillingMode $mode = BillingMode::Live;

    private ?CarbonImmutable $virtualNow = null;

    public function mode(): BillingMode
    {
        return $this->mode;
    }

    public function isTest(): bool
    {
        return $this->mode->isTest();
    }

    /** Whether rows in the current plane carry `livemode = true` (live) or `false` (test). */
    public function livemode(): bool
    {
        return $this->mode->livemode();
    }

    /** Set the resolved mode for the request/pass (called by the console middleware / API auth). */
    public function setMode(BillingMode $mode): void
    {
        $this->mode = $mode;
    }

    public function now(): CarbonImmutable
    {
        return $this->virtualNow ?? CarbonImmutable::now();
    }

    /**
     * Run `$callback` in `$mode`, restoring the prior mode afterwards (even on throw). Used
     * where the request carries no credential to set the plane but a resolved row names it —
     * e.g. a settlement webhook activating a hosted checkout must subscribe the org in the
     * checkout session's OWN plane, not the ambient default, then restore so the rest of the
     * webhook is unaffected.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runInMode(BillingMode $mode, Closure $callback): mixed
    {
        $previousMode = $this->mode;
        $this->mode = $mode;

        try {
            return $callback();
        } finally {
            $this->mode = $previousMode;
        }
    }

    /**
     * Run `$callback` as if it were TEST mode at exactly `$virtualNow`, restoring the prior
     * mode and virtual time afterwards (even on throw). This is how the test-clock advancer
     * steps the world to each due instant: the due-logic run inside sees `now()` = the step
     * time and `livemode()` = false, so it processes only test rows and writes test rows.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAtVirtualTime(CarbonImmutable $virtualNow, Closure $callback): mixed
    {
        $previousMode = $this->mode;
        $previousVirtual = $this->virtualNow;

        $this->mode = BillingMode::Test;
        $this->virtualNow = $virtualNow;

        try {
            return $callback();
        } finally {
            $this->mode = $previousMode;
            $this->virtualNow = $previousVirtual;
        }
    }
}
