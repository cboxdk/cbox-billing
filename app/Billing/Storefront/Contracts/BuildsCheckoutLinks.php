<?php

declare(strict_types=1);

namespace App\Billing\Storefront\Contracts;

/**
 * Builds the checkout deep-link a storefront/paywall CTA sends a buyer to — resolving the
 * configured template (or the default checkout URL) and carrying the plan/price/attribution
 * params. The storefront presenters depend on this contract, so the link policy stays swappable
 * by a host.
 */
interface BuildsCheckoutLinks
{
    /**
     * The checkout URL for a plan/price, with any extra `$attribution` appended as query params.
     *
     * @param  array<string, string>  $attribution
     */
    public function build(?string $template, string $planKey, string $currency, string $interval, int $priceMinor, array $attribution = []): string;
}
