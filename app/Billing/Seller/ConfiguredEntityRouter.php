<?php

declare(strict_types=1);

namespace App\Billing\Seller;

use Cbox\Billing\Seller\Contracts\EntityRouter;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Geo\ValueObjects\Jurisdiction;

/**
 * Chooses the selling entity that issues an invoice for a buyer. This host runs a single
 * selling entity of record (the configured default), so routing is trivial today; the
 * seam is kept a contract so adding a second entity (a UK Ltd, a US Inc) is a change here
 * and nowhere in the callers. The chosen entity drives the tax outcome, so routing is a
 * tax decision, not just branding.
 */
readonly class ConfiguredEntityRouter implements EntityRouter
{
    public function __construct(private SellerCatalog $sellers) {}

    public function routeFor(Jurisdiction $buyer): SellerEntity
    {
        return $this->sellers->default();
    }
}
