<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\Gateways\EnvironmentAwarePaymentGateway;
use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Environments\Gateways\GatewayDelegateMemo;
use App\Billing\Environments\Gateways\StripeGatewayFactory;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\Contracts\BillingClock;
use App\Billing\TestMode\CapturedNotifications;
use App\Billing\TestMode\ClockChargeOutcome;
use App\Billing\TestMode\Contracts\ResolvesTestChargeOutcome;
use App\Billing\TestMode\TestPaymentGateway;
use App\Models\EnvironmentGateway;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the sandbox / test-mode plane. Three thin container responsibilities:
 *
 *  1. The ambient {@see BillingContext} — the one holder of the request's mode + virtual
 *     clock, bound as a singleton and as the app-wide {@see BillingClock} every
 *     time-sensitive billing service reads "now" through. Default is LIVE + real now, so
 *     nothing outside test mode changes.
 *  2. The mode-aware gateway: whatever gateway was configured (Stripe/manual) is WRAPPED so
 *     a test-mode charge routes to the {@see TestPaymentGateway} and can never reach the real
 *     gateway. `extend` wraps the final binding regardless of which provider set it.
 *  3. The deterministic test-charge outcome resolver (per bound test clock).
 *
 * Registered first (see bootstrap/providers.php) so the context/clock exist before any
 * partitioned model boots its plane scope.
 */
class TestModeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BillingContext::class);
        $this->app->singleton(BillingClock::class, static fn (Application $app): BillingContext => $app->make(BillingContext::class));
        $this->app->singleton(EnvironmentRegistry::class);
        $this->app->singleton(CapturedNotifications::class);

        $this->app->singleton(ResolvesTestChargeOutcome::class, ClockChargeOutcome::class);
        $this->app->singleton(TestPaymentGateway::class);

        // The per-environment gateway plumbing: the memoised credentials store, the resolved-
        // delegate memo, and the factory that builds a real Stripe gateway from a plane's own
        // decrypted keys.
        $this->app->singleton(EnvironmentGatewayStore::class);
        $this->app->singleton(GatewayDelegateMemo::class);

        // Wrap the configured gateway so every payment call is ENVIRONMENT-routed. `extend` applies
        // on resolve, so `$globalLive` is whichever gateway (Stripe adapter or ManualPaymentGateway)
        // the other providers ultimately bound from the global env-var config. The resolver charges
        // a plane through its own DB credentials when it has them, falls back to `$globalLive` for
        // production (BC — env-var keys), and to the fake gateway for a keyless sandbox, so a
        // sandbox charge can never reach a real account by accident.
        $this->app->extend(PaymentGateway::class, static fn (PaymentGateway $globalLive, Application $app): EnvironmentAwarePaymentGateway => new EnvironmentAwarePaymentGateway(
            $app->make(BillingContext::class),
            $app->make(EnvironmentGatewayStore::class),
            $globalLive,
            $app->make(TestPaymentGateway::class),
            static fn (EnvironmentGateway $credentials): PaymentGateway => $app->make(StripeGatewayFactory::class)->make($credentials),
            $app->make(GatewayDelegateMemo::class),
        ));
    }
}
