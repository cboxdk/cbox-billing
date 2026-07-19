<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Tax\Exemptions\ExemptingTaxCalculator;
use App\Billing\Tax\Exemptions\ExemptionContext;
use App\Billing\Tax\TaxContextFactory;
use Cbox\Tax\Contracts\TaxCalculator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires customer tax-exemption certificates into the tax path. Two thin bindings, no logic:
 *
 *  1. {@see ExemptionContext} as a singleton — the shared bridge the
 *     {@see TaxContextFactory} activates per organization and the decorator
 *     reads while assessing.
 *  2. Decorate the engine's {@see TaxCalculator} with {@see ExemptingTaxCalculator}, so a
 *     verified certificate flips a would-be-taxed line to exempt — the app's tax-context
 *     layer, not the tax package (which stays exemption-agnostic; see the docs note on why a
 *     first-class exemption concept belongs upstream).
 */
class TaxExemptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExemptionContext::class);

        $this->app->extend(TaxCalculator::class, static fn (TaxCalculator $inner, Application $app): ExemptingTaxCalculator => new ExemptingTaxCalculator(
            $inner,
            $app->make(ExemptionContext::class),
        ));
    }
}
