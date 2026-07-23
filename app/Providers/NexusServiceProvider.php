<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Fx\FxConverter;
use App\Billing\Nexus\DatabasePhysicalNexus;
use App\Billing\Nexus\InvoiceSalesLedger;
use App\Billing\Nexus\NexusReporter;
use App\Billing\Nexus\SellerNexusRegistrations;
use App\Billing\Seller\SellerCatalog;
use Cbox\Nexus\Contracts\NexusEngine;
use Cbox\Nexus\Contracts\NexusRegistrations;
use Cbox\Nexus\Contracts\PhysicalNexus;
use Cbox\Nexus\Contracts\SalesLedger;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the cboxdk/laravel-nexus engine to this app's data. The package binds the
 * engine and its threshold source (the us-tax-data dataset) itself; here we bind
 * the three HOST seams to Eloquent/config-backed implementations so the engine can
 * see the seller's cumulative US sales, existing registrations, and physical
 * presence, and the {@see NexusReporter} that surfaces the result. The engine's own
 * decision logic and thresholds are left untouched.
 */
class NexusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SalesLedger::class, static fn (Application $app): SalesLedger => new InvoiceSalesLedger(
            $app->make(SellerCatalog::class),
            $app->make(FxConverter::class),
        ));

        $this->app->singleton(NexusRegistrations::class, static fn (Application $app): NexusRegistrations => new SellerNexusRegistrations(
            $app->make(SellerCatalog::class),
        ));

        $this->app->singleton(PhysicalNexus::class, static fn (Application $app): PhysicalNexus => new DatabasePhysicalNexus(
            $app->make(SellerCatalog::class),
        ));

        $this->app->singleton(NexusReporter::class, static fn (Application $app): NexusReporter => new NexusReporter(
            $app->make(NexusEngine::class),
            $app->make(SellerCatalog::class),
            $app->make(Config::class)->get('billing.nexus.sole_sales_channel') === true,
        ));
    }
}
