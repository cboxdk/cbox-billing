<?php

declare(strict_types=1);

namespace App\Billing\Tax;

use App\Billing\Seller\SellerCatalog;
use App\Billing\Tax\Exemptions\ExemptionContext;
use App\Models\Organization;
use Cbox\Billing\Quote\ValueObjects\QuoteContext;
use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Geo\ValueObjects\TaxProfile;
use Cbox\Tax\Enums\CustomerType;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Assembles the {@see QuoteContext} a quote/invoice is priced against for an organization
 * — the buyer side of `tax = f(seller registrations, buyer place, product)`.
 *
 * The buyer's place of supply comes from the org's billing address. When the org has no
 * country, the place is a deliberately UN-modelled jurisdiction, so the quote builder
 * takes its tax-pending path (net prices, honest reason) rather than inventing a rate —
 * and an invoice cannot be finalized until a real address is set.
 */
readonly class TaxContextFactory
{
    public function __construct(
        private JurisdictionRepository $jurisdictions,
        private SellerCatalog $sellers,
        private ExemptionContext $exemptions,
        private Config $config,
    ) {}

    public function forOrganization(Organization $organization): QuoteContext
    {
        // Load this org's verified, non-expired exemption certificates as the active set the
        // tax-calculator decorator consults while it prices the quote that follows. Both the
        // preview and the charge path build through here, so an exemption applies identically
        // to what a customer is shown and what they are billed (H6: preview == charge).
        $this->exemptions->activate($organization);

        return new QuoteContext(
            place: $this->placeOfSupply($organization),
            customer: CustomerType::Business,
            seller: $this->sellers->default()->toSellerRegistrations(),
            pricing: TaxPricing::fromConfig($this->config),
            customerTaxIdValidated: $organization->tax_id_validated,
        );
    }

    private function placeOfSupply(Organization $organization): Jurisdiction
    {
        if ($organization->billing_country === null) {
            return $this->unresolvedPlace();
        }

        $subdivision = $organization->billing_subdivision !== null
            ? new SubdivisionCode($organization->billing_subdivision)
            : null;

        $resolved = $this->jurisdictions->find(new CountryCode($organization->billing_country), $subdivision);

        return $resolved ?? $this->unresolvedPlace();
    }

    /**
     * A place we cannot tax on: an un-modelled jurisdiction whose regime is null, so the
     * tax engine refuses it and the quote is returned tax-pending — the honest answer for
     * an org without a resolvable address.
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
