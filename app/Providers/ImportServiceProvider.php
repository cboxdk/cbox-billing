<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Import\Adapters\AdapterRegistry;
use App\Billing\Import\Adapters\ChargebeeAdapter;
use App\Billing\Import\Adapters\RecurlyAdapter;
use App\Billing\Import\Adapters\StripeAdapter;
use App\Billing\Import\Api\NullSourceApiPuller;
use App\Billing\Import\BillingImporter;
use App\Billing\Import\Contracts\SourceApiPuller;
use App\Billing\Import\ImportRunner;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the import / migration module: the adapter registry (the ordered set of every supported
 * source adapter — Stripe / Chargebee / Recurly) and the honest no-op live-pull default.
 * Everything is contract-bound so a host or plugin can add a provider adapter or bind a real
 * live-credentialed pull without editing calling code. The pipeline itself
 * ({@see BillingImporter}) resolves from the container over the real domain
 * services, so imports go through the same write paths the console does.
 */
class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class, static fn (Container $app): AdapterRegistry => new AdapterRegistry([
            $app->make(StripeAdapter::class),
            $app->make(ChargebeeAdapter::class),
            $app->make(RecurlyAdapter::class),
        ]));

        // The shipped default is honest: no live provider client. The file/dump export path is the
        // real migration path; a host binds its own puller to enable a credentialed live pull.
        $this->app->bind(SourceApiPuller::class, NullSourceApiPuller::class);

        // The runner stages the raw export on the local disk between the dry-run and the commit.
        $this->app->singleton(ImportRunner::class, static fn (Container $app): ImportRunner => new ImportRunner(
            $app->make(AdapterRegistry::class),
            $app->make(BillingImporter::class),
            Storage::disk('local'),
        ));
    }
}
