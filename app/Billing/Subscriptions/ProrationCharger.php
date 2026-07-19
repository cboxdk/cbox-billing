<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Subscriptions\Contracts\CollectsProration;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Invoice;
use App\Models\Subscription;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Illuminate\Contracts\Container\Container;

/**
 * Turns a previewed mid-cycle "due now" into a real receivable and charge (H6): it issues a
 * prorated invoice through the engine's quote/invoicer path — so the charged gross equals
 * the previewed due-now — then collects it through the established renewal charge path
 * ({@see RetriesPayments::chargeRenewal}), which settles on success and enters the smart-retry
 * (PastDue) flow on a hard decline. A non-positive amount is a no-op.
 *
 * {@see RetriesPayments} is resolved lazily from the container rather than injected: the
 * charge path reaches the {@see InvoicePaymentApplier} and
 * {@see SubscribesOrganizations}, and this collector is
 * itself a dependency of {@see SubscriptionService} (the SubscribesOrganizations binding), so
 * an eager injection would form a construction cycle. Deferring the resolve to call time —
 * after the singletons exist — breaks it without a parallel charge implementation.
 */
readonly class ProrationCharger implements CollectsProration
{
    public function __construct(
        private GeneratesInvoices $invoices,
        private Container $container,
    ) {}

    public function collect(Subscription $subscription, Money $dueNow, string $description): ?Invoice
    {
        // A downgrade credit or a net-zero change (a flat-plan seat move) owes nothing now.
        if (! $dueNow->isPositive()) {
            return null;
        }

        $invoice = $this->invoices->issueDueNow($subscription, $dueNow, $description);

        $this->container->make(RetriesPayments::class)->chargeRenewal($invoice, $subscription);

        return $invoice;
    }
}
