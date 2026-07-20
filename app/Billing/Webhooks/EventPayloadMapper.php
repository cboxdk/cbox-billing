<?php

declare(strict_types=1);

namespace App\Billing\Webhooks;

use App\Billing\Webhooks\Enums\WebhookEvent;
use App\Billing\Webhooks\Events\CouponRedeemed;
use App\Billing\Webhooks\Events\DunningExhausted;
use App\Billing\Webhooks\Events\LicenseRevoked;
use App\Billing\Webhooks\Events\PaymentFailed;
use App\Billing\Webhooks\Events\SubscriptionCanceled;
use App\Billing\Webhooks\Events\SubscriptionCreated;
use App\Billing\Webhooks\ValueObjects\ResolvedEvent;
use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Events\InvoiceIssued;
use Cbox\Billing\Events\LicenseIssued;
use Cbox\Billing\Events\PaymentSettled;
use Cbox\Billing\Events\SubscriptionChanged;
use Cbox\Billing\Events\SubscriptionRenewed;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Retention\Events\SubscriptionCancellationRequested;
use DateTimeInterface;

/**
 * Maps a source domain event object to its outbound {@see ResolvedEvent} — the single place that
 * knows how each engine/app event becomes a catalog `type`, a stable idempotency `id`, and a
 * JSON-safe payload. Returns null for an event the catalog does not cover (deny-by-default: an
 * unmapped event is never delivered).
 *
 * The idempotency `id` is derived from the event's natural business key (invoice number, payment
 * reference, subscription id + moment, license id, …) so a re-emitted or re-dispatched domain
 * event collapses onto the same delivery row rather than double-delivering.
 */
class EventPayloadMapper
{
    public function resolve(object $event): ?ResolvedEvent
    {
        return match (true) {
            $event instanceof InvoiceIssued => $this->invoiceIssued($event),
            $event instanceof PaymentSettled => $this->paymentSettled($event),
            $event instanceof PaymentFailed => $this->paymentFailed($event),
            $event instanceof CreditNoteIssued => $this->creditNoteIssued($event),
            $event instanceof SubscriptionCreated => $this->subscriptionCreated($event),
            $event instanceof SubscriptionChanged => $this->subscriptionChanged($event),
            $event instanceof SubscriptionRenewed => $this->subscriptionRenewed($event),
            $event instanceof SubscriptionCanceled => $this->subscriptionCanceled($event),
            $event instanceof SubscriptionCancellationRequested => $this->cancellationRequested($event),
            $event instanceof LicenseIssued => $this->licenseIssued($event),
            $event instanceof LicenseRevoked => $this->licenseRevoked($event),
            $event instanceof CouponRedeemed => $this->couponRedeemed($event),
            $event instanceof DunningExhausted => $this->dunningExhausted($event),
            default => null,
        };
    }

    private function invoiceIssued(InvoiceIssued $event): ResolvedEvent
    {
        $invoice = $event->invoice;
        $totals = $invoice->totals;

        return new ResolvedEvent(WebhookEvent::InvoiceIssued, 'invoice:'.$invoice->number, [
            'number' => $invoice->number,
            'account' => $event->account,
            'currency' => $invoice->currency,
            'issued_at' => $this->date($invoice->issuedAt),
            'line_count' => count($invoice->lines),
            'totals' => [
                'net' => $this->money($totals->net),
                'tax' => $this->money($totals->tax),
                'gross' => $this->money($totals->gross),
                'due_now' => $this->money($totals->dueNow),
            ],
        ]);
    }

    private function paymentSettled(PaymentSettled $event): ResolvedEvent
    {
        $gatewayReference = $event->result->gatewayReference;

        return new ResolvedEvent(
            WebhookEvent::PaymentSettled,
            'payment:'.$event->reference.':'.($gatewayReference ?? 'na'),
            [
                'reference' => $event->reference,
                'amount' => $this->money($event->amount),
                'status' => $event->result->status->value,
                'gateway_reference' => $gatewayReference,
            ],
        );
    }

    private function paymentFailed(PaymentFailed $event): ResolvedEvent
    {
        $invoice = $event->invoice;

        return new ResolvedEvent(
            WebhookEvent::PaymentFailed,
            'payment_failed:'.$invoice->id.':'.$event->attempt,
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'organization_id' => $invoice->organization_id,
                'subscription_id' => $event->subscription->id,
                'attempt' => $event->attempt,
                'max_attempts' => $event->maxAttempts,
                'next_attempt_at' => $event->nextAttemptAt?->toIso8601String(),
                'reference' => $event->reference,
                'amount' => ['minor' => $invoice->total_minor, 'currency' => $invoice->currency],
            ],
        );
    }

    private function creditNoteIssued(CreditNoteIssued $event): ResolvedEvent
    {
        $note = $event->creditNote;

        return new ResolvedEvent(WebhookEvent::CreditNoteIssued, 'credit_note:'.$note->number, [
            'number' => $note->number,
            'invoice_number' => $note->invoiceNumber,
            'account' => $note->account,
            'currency' => $note->currency,
            'reason' => $note->reason->value,
            'kind' => $note->kind->value,
            'issued_at' => $this->date($note->issuedAt),
            'net' => $this->money($note->net),
            'tax' => $this->money($note->tax),
            'gross' => $this->money($note->gross),
        ]);
    }

    private function subscriptionCreated(SubscriptionCreated $event): ResolvedEvent
    {
        $subscription = $event->subscription;

        return new ResolvedEvent(WebhookEvent::SubscriptionCreated, 'subscription:'.$subscription->id.':created', [
            'id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'plan_id' => $subscription->plan_id,
            'status' => $subscription->status->value,
            'seats' => $subscription->seats,
            'current_period_start' => $subscription->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
        ]);
    }

    private function subscriptionChanged(SubscriptionChanged $event): ResolvedEvent
    {
        $subscription = $event->subscription;
        $change = $event->change;

        return new ResolvedEvent(
            WebhookEvent::SubscriptionChanged,
            'subscription:'.$subscription->id.':change:'.$change->newPriceId,
            [
                'id' => $subscription->id,
                'organization_id' => $subscription->organizationId,
                'product_id' => $subscription->productId,
                'price_id' => $subscription->priceId,
                'period_index' => $subscription->periodIndex,
                'change' => [
                    'new_price_id' => $change->newPriceId,
                    'new_product_id' => $change->newProductId,
                    'changes_plan' => $change->changesPlan(),
                ],
            ],
        );
    }

    private function subscriptionRenewed(SubscriptionRenewed $event): ResolvedEvent
    {
        $subscription = $event->subscription;

        return new ResolvedEvent(
            WebhookEvent::SubscriptionRenewed,
            'subscription:'.$subscription->id.':renewed:'.$subscription->periodIndex,
            [
                'id' => $subscription->id,
                'organization_id' => $subscription->organizationId,
                'product_id' => $subscription->productId,
                'price_id' => $subscription->priceId,
                'period_index' => $subscription->periodIndex,
                'previous_period_index' => $event->previous->periodIndex,
            ],
        );
    }

    private function subscriptionCanceled(SubscriptionCanceled $event): ResolvedEvent
    {
        $subscription = $event->subscription;

        return new ResolvedEvent(WebhookEvent::SubscriptionCanceled, 'subscription:'.$subscription->id.':canceled', [
            'id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'plan_id' => $subscription->plan_id,
            'canceled_at' => $subscription->canceled_at?->toIso8601String(),
        ]);
    }

    private function cancellationRequested(SubscriptionCancellationRequested $event): ResolvedEvent
    {
        $subscription = $event->subscription;
        $response = $event->response;

        return new ResolvedEvent(
            WebhookEvent::SubscriptionCancellationRequested,
            'subscription:'.$subscription->id.':cancel_requested',
            [
                'id' => $subscription->id,
                'organization_id' => $subscription->organizationId,
                'account' => $event->account,
                'product_id' => $subscription->productId,
                'price_id' => $subscription->priceId,
                'reason' => $response?->reasonKey,
                'comment' => $response?->comment,
            ],
        );
    }

    private function licenseIssued(LicenseIssued $event): ResolvedEvent
    {
        $license = $event->license;

        return new ResolvedEvent(WebhookEvent::LicenseIssued, 'license:'.$license->id, [
            'id' => $license->id,
            'customer_id' => $license->customerId,
            'deployment_id' => $license->deploymentId,
            'plan' => $license->plan,
            'licensed_domain' => $license->licensedDomain,
            'issued_at' => $this->date($license->issuedAt),
            'not_before' => $this->date($license->notBefore),
            'expires_at' => $this->date($license->expiresAt),
        ]);
    }

    private function licenseRevoked(LicenseRevoked $event): ResolvedEvent
    {
        return new ResolvedEvent(WebhookEvent::LicenseRevoked, 'license:'.$event->licenseId.':revoked', [
            'license_id' => $event->licenseId,
            'reason' => $event->reason,
        ]);
    }

    private function couponRedeemed(CouponRedeemed $event): ResolvedEvent
    {
        $coupon = $event->coupon;
        $subscription = $event->subscription;

        return new ResolvedEvent(
            WebhookEvent::CouponRedeemed,
            'coupon:'.$coupon->id.':sub:'.$subscription->id,
            [
                'code' => $coupon->code,
                'coupon_id' => $coupon->id,
                'discount_type' => $coupon->discount_type,
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
            ],
        );
    }

    private function dunningExhausted(DunningExhausted $event): ResolvedEvent
    {
        $invoice = $event->invoice;

        return new ResolvedEvent(WebhookEvent::DunningExhausted, 'dunning_exhausted:'.$invoice->id, [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'organization_id' => $invoice->organization_id,
            'subscription_id' => $event->subscription->id,
            'attempts' => $event->attempts,
            'amount' => ['minor' => $invoice->total_minor, 'currency' => $invoice->currency],
        ]);
    }

    /**
     * @return array{minor: int, currency: string}
     */
    private function money(Money $money): array
    {
        return ['minor' => $money->minor(), 'currency' => $money->currency()];
    }

    private function date(DateTimeInterface $date): string
    {
        return $date->format(DateTimeInterface::ATOM);
    }
}
