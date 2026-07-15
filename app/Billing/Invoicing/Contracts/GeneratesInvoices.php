<?php

declare(strict_types=1);

namespace App\Billing\Invoicing\Contracts;

use App\Models\Invoice;
use App\Models\Subscription;
use Cbox\Billing\Invoice\Contracts\Invoicer;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\Quote;

/**
 * Generates invoices for a subscription's billing period. The concrete service composes
 * the engine's {@see QuoteBuilder} (catalog price + tax) and
 * {@see Invoicer} (legal numbering + currency lock), then
 * writes the app's {@see Invoice} + line rows. Controllers and commands depend on this.
 */
interface GeneratesInvoices
{
    /**
     * Preview the taxed quote for `$subscription`'s current period without issuing an
     * invoice. A tax-pending quote (org with no resolvable address) is returned as such —
     * net prices with an honest reason.
     */
    public function quoteFor(Subscription $subscription): Quote;

    /**
     * Issue and persist an invoice for `$subscription`'s current period. Refuses when the
     * quote is tax-pending — an invoice must show a final amount.
     */
    public function generate(Subscription $subscription): Invoice;
}
