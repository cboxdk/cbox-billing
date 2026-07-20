<?php

namespace Tests;

use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed a CONFIG seeder into BOTH the production and the default sandbox plane.
     *
     * Config (catalog/branding/templates/storefront) is environment-scoped (Env Wave 2), so a
     * seeder run in the default (production) plane populates ONLY production. A cross-plane test
     * that also acts in the sandbox needs the same catalog present there — exactly as a real
     * sandbox would (seeded, or cloned from production). This seeds each plane independently, so
     * the two catalogs are isolated rows sharing the same natural keys.
     *
     * @param  class-string  ...$seeders
     */
    protected function seedConfigInAllPlanes(string ...$seeders): void
    {
        $context = app(BillingContext::class);

        foreach ([Environment::defaultProduction(), Environment::defaultSandbox()] as $plane) {
            $context->runInEnvironment($plane, function () use ($seeders): void {
                $this->seed($seeders);
            });
        }
    }
}
