<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Contracts;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
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

    /**
     * Operator re-queued an invoice email → send the issued-invoice mail again, built from
     * the invoice's own data (no subscription required, so an ad-hoc invoice resends too).
     */
    public function invoiceResent(Invoice $invoice): void;

    /** Settled-payment webhook applied → send the receipt for the now-paid invoice. */
    public function paymentReceipt(Invoice $invoice): void;

    /** A dunning step fired → notify the account of the past-due balance (and any suspension). */
    public function dunningNotice(Organization $organization, Money $amountDue, bool $suspended, ?DateTimeInterface $oldestDueAt): void;

    /**
     * A renewal charge failed and the smart-retry schedule is chasing it → tell the customer
     * their payment failed, when the next attempt is (or that retries are exhausted).
     * `$attempt` is 0 for the initial failure notice, then the attempt number for each
     * retry; `$exhausted` is true on the final, give-up notice.
     */
    public function paymentRetryFailed(Subscription $subscription, Invoice $invoice, int $attempt, int $maxAttempts, ?DateTimeInterface $nextAttemptAt, bool $exhausted): void;

    /** Ahead of a trial's conversion → remind the customer their trial ends (and will charge). */
    public function trialEnding(Subscription $subscription, DateTimeInterface $trialEndsAt): void;

    /** Ahead of a term renewal → remind the customer their subscription renews. */
    public function renewalReminder(Subscription $subscription): void;

    /**
     * A plan change / cancellation → confirm it. `$changeType` is `plan_change`, `canceled`
     * or `cancel_scheduled`.
     */
    public function subscriptionChanged(Subscription $subscription, string $changeType, ?string $previousPlanName = null): void;

    /** A license was issued/reissued → deliver the key + install notes to the customer. */
    public function licenseDelivered(Organization $organization, IssuedLicense $license, bool $reissued): void;

    /**
     * Ahead of a retiring plan's cutoff → tell the affected subscriber their plan retires on
     * `$retiresAtLabel`, that their next renewal (`$renewalDueLabel`) is the deadline to
     * choose a new plan, and — when one is configured — the default they fall to otherwise
     * (ADR-0016).
     */
    public function planRetiring(Subscription $subscription, Plan $plan, string $retiresAtLabel, string $renewalDueLabel, ?string $defaultSuccessorName): void;
}
