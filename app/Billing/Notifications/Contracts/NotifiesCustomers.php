<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Contracts;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use Cbox\Billing\Money\Money;
use DateTimeInterface;

/**
 * The customer-facing notification surface. Every transactional email the billing lifecycle
 * sends goes through one of these methods; the implementation resolves the recipient from
 * the organization's billing-contact data and queues the branded Mailable. Lifecycle
 * services depend on this contract, never on the mailer or a concrete Mailable — so a test
 * can fake mail and a host can swap the transport.
 *
 * Each method is a no-op (logged) when the organization has no billing contact on file — a
 * missing recipient never throws into the lifecycle path.
 */
interface NotifiesCustomers
{
    /** Invoice finalized → send the customer the issued invoice. */
    public function invoiceIssued(Invoice $invoice, Subscription $subscription): void;

    /** Settled-payment webhook applied → send the receipt for the now-paid invoice. */
    public function paymentReceipt(Invoice $invoice): void;

    /** A dunning step fired → notify the account of the past-due balance (and any suspension). */
    public function dunningNotice(Organization $organization, Money $amountDue, bool $suspended, ?DateTimeInterface $oldestDueAt): void;

    /** Ahead of a term renewal → remind the customer their subscription renews. */
    public function renewalReminder(Subscription $subscription): void;

    /**
     * A plan change / cancellation → confirm it. `$changeType` is `plan_change`, `canceled`
     * or `cancel_scheduled`.
     */
    public function subscriptionChanged(Subscription $subscription, string $changeType, ?string $previousPlanName = null): void;

    /** A license was issued/reissued → deliver the key + install notes to the customer. */
    public function licenseDelivered(Organization $organization, IssuedLicense $license, bool $reissued): void;
}
