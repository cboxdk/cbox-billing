<?php

declare(strict_types=1);

namespace App\Billing\Cpq\Contracts;

use App\Billing\Cpq\QuoteProvisioner;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Quote;
use App\Models\Subscription;

/**
 * Turns an accepted {@see Quote} into a real subscription. Idempotent: a quote provisions at most
 * once — a second call returns the already-provisioned subscription without opening another. The
 * concrete service ({@see QuoteProvisioner}) wires the quote's primary plan line,
 * seats, currency and coupon through the engine
 * {@see SubscribesOrganizations} seam.
 */
interface ProvisionsFromQuote
{
    /**
     * Provision (or return the already-provisioned) subscription for `$quote`.
     */
    public function provision(Quote $quote): Subscription;
}
