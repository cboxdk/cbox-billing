<?php

declare(strict_types=1);

namespace App\Billing\Mode;

use App\Billing\Mode\Contracts\BillingClock;
use App\Billing\TestMode\ModeAwarePaymentGateway;
use App\Models\Environment;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The one ambient holder of the request's (or CLI pass's) active {@see Environment} — the billing
 * PLANE — and, while a test clock is being advanced, its virtual "now". Bound as a singleton and
 * as the app's {@see BillingClock}, so:
 *
 *  - the {@see EnvironmentScope} on every partitioned model reads {@see environmentKey()} to
 *    filter to the current plane (deny-by-default: production unless a credential names another);
 *  - the {@see ModeAwarePaymentGateway} reads {@see isTest()} to route a
 *    sandbox charge to the fake gateway;
 *  - every time-sensitive billing service reads {@see now()} for the current instant.
 *
 * It starts in PRODUCTION with no virtual time (a DB-free in-memory placeholder — no query at
 * boot or mid-migration), so nothing outside a sandbox changes: `now()` is real now and the scope
 * selects `environment = 'production'`. The console middleware / API authenticator / public
 * token-bootstrap set the environment from the credential or the resolved row; the test-clock
 * advancer scopes a virtual-time window with {@see runAtVirtualTime()}.
 *
 * The legacy test/live {@see BillingMode} surface ({@see mode()}, {@see livemode()},
 * {@see setMode()}, {@see runInMode()}) is retained as a thin bridge over the environment, so
 * existing callers and the synced `livemode` mirror column keep working unchanged.
 */
class BillingContext implements BillingClock
{
    private ?Environment $environment = null;

    private ?CarbonImmutable $virtualNow = null;

    /** The active plane — the set environment, or the DB-free production default. */
    public function environment(): Environment
    {
        return $this->environment ??= Environment::defaultProduction();
    }

    /** The stable key of the active plane — what {@see EnvironmentScope} filters every read by. */
    public function environmentKey(): string
    {
        return $this->environment()->key;
    }

    /** Set the resolved environment for the request/pass (console middleware / API auth / token bootstrap). */
    public function setEnvironment(Environment $environment): void
    {
        $this->environment = $environment;
    }

    /** The legacy plane enum for the active environment (production → live, sandbox → test). */
    public function mode(): BillingMode
    {
        return $this->environment()->billingMode();
    }

    /** Whether charges in the active plane route to the fake gateway (the sandbox / test-key plane). */
    public function isTest(): bool
    {
        return $this->environment()->gatewayKeyMode()->isTest();
    }

    /** The `livemode` mirror rows in the active plane carry (production → true, sandbox → false). */
    public function livemode(): bool
    {
        return $this->environment()->livemode();
    }

    /** BC bridge: set the plane from a legacy test/live mode (live → production, test → sandbox). */
    public function setMode(BillingMode $mode): void
    {
        $this->environment = Environment::forBillingMode($mode);
    }

    public function now(): CarbonImmutable
    {
        return $this->virtualNow ?? CarbonImmutable::now();
    }

    /**
     * Run `$callback` in `$environment`, restoring the prior plane afterwards (even on throw).
     * Used where the request carries no credential to set the plane but a resolved row names it —
     * e.g. a settlement webhook activating a hosted checkout must subscribe the org in the
     * checkout session's OWN plane, not the ambient default, then restore.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runInEnvironment(Environment $environment, Closure $callback): mixed
    {
        $previous = $this->environment;
        $this->environment = $environment;

        try {
            return $callback();
        } finally {
            $this->environment = $previous;
        }
    }

    /**
     * BC bridge for {@see runInEnvironment()} from a legacy test/live mode.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runInMode(BillingMode $mode, Closure $callback): mixed
    {
        return $this->runInEnvironment(Environment::forBillingMode($mode), $callback);
    }

    /**
     * Run `$callback` at exactly `$virtualNow` in the CURRENT plane, restoring the prior virtual
     * time afterwards (even on throw). This is how the test-clock advancer steps the world to each
     * due instant: the due-logic run inside sees `now()` = the step time and the ACTIVE sandbox
     * plane the advance is running in — so a clock bound to a NAMED sandbox processes and writes
     * that sandbox's rows, rather than being forced onto the default sandbox (where its
     * subscriptions are invisible). The caller is already in the clock's plane (set by the API
     * token / console), so this only fixes the virtual clock; it must NOT override the plane.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAtVirtualTime(CarbonImmutable $virtualNow, Closure $callback): mixed
    {
        $previousVirtual = $this->virtualNow;

        $this->virtualNow = $virtualNow;

        try {
            return $callback();
        } finally {
            $this->virtualNow = $previousVirtual;
        }
    }
}
