<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Nexus\ConfigPhysicalNexus;
use App\Billing\Nexus\InvoiceSalesLedger;
use App\Billing\Nexus\SellerNexusRegistrations;
use App\Billing\Seller\SellerCatalog;
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
 * presence. The engine's own decision logic and thresholds are left untouched.
 */
class NexusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SalesLedger::class, static fn (Application $app): SalesLedger => new InvoiceSalesLedger(
            $app->make(SellerCatalog::class),
        ));

        $this->app->singleton(NexusRegistrations::class, static fn (Application $app): NexusRegistrations => new SellerNexusRegistrations(
            $app->make(SellerCatalog::class),
        ));

        $this->app->singleton(PhysicalNexus::class, static function (Application $app): PhysicalNexus {
            $states = $app->make(Config::class)->get('nexus.physical_presence', []);

            return new ConfigPhysicalNexus(
                is_array($states) ? array_values(array_filter($states, 'is_string')) : [],
            );
        });
    }
}
