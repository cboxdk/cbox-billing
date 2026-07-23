<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Tax\TaxContextFactory;
use App\Models\Organization;
use App\Models\SellerEntity;
use Cbox\Tax\Enums\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tax pricing convention is configurable, not hardcoded: the quote/invoice context the
 * engine prices against carries EXCLUSIVE by default and INCLUSIVE when `billing.tax.pricing`
 * says so, with an unknown value denying-by-default back to exclusive.
 */
class TaxPricingModeTest extends TestCase
{
    use RefreshDatabase;

    private function organization(): Organization
    {
        SellerEntity::query()->create([
            'id' => 'us-co', 'legal_name' => 'US Co', 'registration_number' => 'US-0001',
            'establishment' => 'US', 'currency' => 'USD', 'invoice_prefix' => 'USCO', 'is_default' => true,
        ]);

        return Organization::query()->create([
            'id' => 'ca-buyer', 'name' => 'CA Buyer', 'billing_email' => 'ca@example.test',
            'billing_country' => 'US', 'billing_subdivision' => 'US-CA', 'billing_currency' => 'USD',
            'tax_id_validated' => false,
        ]);
    }

    public function test_defaults_to_tax_exclusive(): void
    {
        $context = $this->app->make(TaxContextFactory::class)->forOrganization($this->organization());

        $this->assertSame(Pricing::Exclusive, $context->pricing);
    }

    public function test_honours_inclusive_pricing_from_config(): void
    {
        config(['billing.tax.pricing' => 'inclusive']);

        $context = $this->app->make(TaxContextFactory::class)->forOrganization($this->organization());

        $this->assertSame(Pricing::Inclusive, $context->pricing);
    }

    public function test_unknown_pricing_falls_back_to_exclusive(): void
    {
        config(['billing.tax.pricing' => 'nonsense']);

        $context = $this->app->make(TaxContextFactory::class)->forOrganization($this->organization());

        $this->assertSame(Pricing::Exclusive, $context->pricing);
    }
}
