<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Seller\SellerCatalog;
use App\Billing\Tax\TaxContextFactory;
use App\Billing\Tax\TaxPricing;
use App\Models\Organization;
use App\Models\Quote;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Geo\ValueObjects\TaxProfile;
use Cbox\Tax\Enums\CustomerType;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Builds the {@see QuoteContext} a sales quote is priced against — the buyer/seller side of
 * `tax = f(seller registrations, buyer place, product)`. Unlike {@see TaxContextFactory}
 * (which is org-bound and activates exemptions for a live invoice), a sales quote may be for a
 * pre-account PROSPECT with no {@see Organization} yet, so the place of supply is read
 * from the linked org when there is one and is otherwise a deliberately un-modelled jurisdiction —
 * the quote is then priced tax-pending (net prices, honest reason) rather than at a fabricated
 * rate. The issuing entity is the quote's own seller (multi-entity routing), falling back to the
 * default seller.
 */
readonly class QuoteTaxContextFactory
{
    public function __construct(
        private JurisdictionRepository $jurisdictions,
        private SellerCatalog $sellers,
        private Config $config,
    ) {}

    public function forQuote(Quote $quote): QuoteContext
    {
        $seller = $quote->seller_entity_id !== null && $quote->seller_entity_id !== ''
            ? $this->sellers->entity($quote->seller_entity_id)
            : $this->sellers->default();

        $organization = $quote->organization;

        return new QuoteContext(
            place: $this->placeOfSupply($quote),
            customer: CustomerType::Business,
            seller: $seller->toSellerRegistrations(),
            pricing: TaxPricing::fromConfig($this->config),
            customerTaxIdValidated: $organization instanceof Organization && $organization->tax_id_validated,
        );
    }

    private function placeOfSupply(Quote $quote): Jurisdiction
    {
        $organization = $quote->organization;

        if ($organization === null || $organization->billing_country === null) {
            return $this->unresolvedPlace();
        }

        $subdivision = $organization->billing_subdivision !== null
            ? new SubdivisionCode($organization->billing_subdivision)
            : null;

        $resolved = $this->jurisdictions->find(new CountryCode($organization->billing_country), $subdivision);

        return $resolved ?? $this->unresolvedPlace();
    }

    /**
     * An un-modelled jurisdiction whose regime is null, so the tax engine refuses it and the
     * quote is returned tax-pending — the honest answer for a prospect without a resolvable address.
     */
    private function unresolvedPlace(): Jurisdiction
    {
        return new Jurisdiction(
            country: new CountryCode('ZZ'),
            countryName: 'Unknown',
            currency: $this->sellers->default()->defaultCurrency,
            taxProfile: TaxProfile::notModeled(),
        );
    }
}
