<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\EnvironmentScope;
use App\Billing\Payments\Exceptions\SettlementRejected;
use App\Models\BillingSession;
use App\Models\Environment;
use App\Models\Invoice;
use Cbox\Billing\Events\PaymentSettled;
use Cbox\Billing\Payment\Contracts\InvoicePaymentApplier;
use Cbox\Billing\Payment\Contracts\ProcessedEventStore;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\ValueObjects\IngestOutcome;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\Webhook\DefaultWebhookIngest;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The app's exactly-once webhook ingest — the engine's {@see DefaultWebhookIngest}
 * reimplemented to close two holes the default cannot, since it is a vendor class:
 *
 *  1. PLANE BOOTSTRAP (HP1). The settlement route carries no credential to set the request's
 *     plane, so the default ran the whole apply — including the mode-scoped invoice lookup and
 *     the dedup guards — under the worker's ambient LIVE default. A TEST settlement therefore
 *     touched LIVE data. Here the reference is resolved to its OWNING plane FIRST (an UNSCOPED
 *     lookup by reference: the invoice number, else the checkout session's payment reference),
 *     then the entire ingest runs {@see BillingContext::runInEnvironment()} in that plane — so a
 *     sandbox settlement resolves, applies, and deduplicates strictly against that environment.
 *
 *  2. REJECTION-AWARE DEDUP. The default writes the settle-once + processed-event guards
 *     UNCONDITIONALLY after the applier returns, even when the applier refused the settlement
 *     (wrong amount/currency). That consumed the guard, so a later CORRECT settlement for the
 *     same reference collapsed to a no-op "already settled" duplicate and never applied. Here the
 *     applier signals a refusal with {@see SettlementRejected}; the ingest catches it and returns
 *     WITHOUT writing either guard, so the corrected retry applies cleanly. The applier's audit
 *     record (written before the throw) still commits with the surrounding transaction.
 *
 * The exactly-once ordering is otherwise identical to the engine default: the effect is applied
 * FIRST and the durable guards written only after it returns, so a crash mid-apply persists
 * nothing and the gateway's re-delivery re-applies exactly once.
 */
readonly class PlaneAwareWebhookIngest implements WebhookIngest
{
    public function __construct(
        private ProcessedEventStore $processed,
        private SettledPaymentStore $settled,
        private InvoicePaymentApplier $applier,
        private BillingContext $context,
        private EnvironmentRegistry $environments,
        private ?Dispatcher $events = null,
    ) {}

    public function ingest(WebhookEvent $event): IngestOutcome
    {
        // Set the request's plane from the reference's OWNING plane before any scoped read.
        return $this->context->runInEnvironment($this->planeFor($event), fn (): IngestOutcome => $this->ingestInPlane($event));
    }

    private function ingestInPlane(WebhookEvent $event): IngestOutcome
    {
        // A non-settlement event carries no paid effect: dedup it on the event id and surface its
        // kind so the caller can react (an SCA challenge / processing notice is recorded but never
        // activated). Only the succeeded settlement below ever moves the invoice.
        if (! $event->isSettlement()) {
            if (! $this->processed->remember($event->id)) {
                return IngestOutcome::duplicateEvent($event);
            }

            return match ($event->type) {
                WebhookEventType::RequiresAction => IngestOutcome::requiresAction($event),
                WebhookEventType::Processing => IngestOutcome::processing($event),
                default => IngestOutcome::ignored($event),
            };
        }

        // The authoritative guard: has this payment/invoice reference already settled (in THIS
        // plane)? A re-delivery whose effect already persisted collapses here to a no-op.
        if ($this->settled->isSettled($event->reference)) {
            $this->processed->remember($event->id);

            return IngestOutcome::alreadySettled($event);
        }

        // Apply the effect FIRST. If the host crashes here nothing below runs, so neither guard
        // persists and the re-delivery re-applies once.
        $result = $event->toPaymentResult();

        try {
            $this->applier->markPaid($event->reference, $event->amount, $result);
        } catch (SettlementRejected) {
            // A refused settlement (wrong amount/currency) is already flagged in the audit log.
            // Do NOT write the settle-once / processed guards: a subsequent CORRECT settlement for
            // this reference must still apply rather than be dropped as a duplicate.
            return IngestOutcome::ignored($event);
        }

        // Commit the guards — in production these ride the controller's single transaction with the
        // effect above, so claim + effect are one atomic, exactly-once unit.
        $this->settled->settle($event->reference);
        $this->processed->remember($event->id);

        // Announce the settlement only on the applying call (fires exactly once per settled ref).
        $this->events?->dispatch(new PaymentSettled($event->reference, $event->amount, $result));

        return IngestOutcome::applied($event);
    }

    /**
     * Resolve the plane the reference lives in WITHOUT a plane scope: the settlement route carries
     * no credential, so we read the owning plane from the invoice (settlements/renewals) or the
     * pending checkout session (hosted activation). A reference that matches neither falls back to
     * the ambient plane — nothing will match under it either, so the apply simply no-ops.
     */
    private function planeFor(WebhookEvent $event): Environment
    {
        $reference = $event->reference;

        $owner = Invoice::query()
            ->withoutGlobalScope(EnvironmentScope::class)
            ->where('number', $reference)
            ->first();

        if (! $owner instanceof Invoice) {
            $owner = BillingSession::query()
                ->withoutGlobalScope(EnvironmentScope::class)
                ->where('payment_reference', $reference)
                ->where('type', 'checkout')
                ->first();
        }

        if ($owner === null) {
            return $this->context->environment();
        }

        $key = $owner->getAttribute('environment');

        return is_string($key) && $key !== ''
            ? $this->environments->resolve($key)
            : $this->context->environment();
    }
}
