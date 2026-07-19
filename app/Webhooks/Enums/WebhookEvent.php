<?php

declare(strict_types=1);

namespace App\Webhooks\Enums;

use App\Webhooks\WebhookEventSubscriber;

/**
 * The canonical outbound event catalog — the closed set of `type` strings an integrator may
 * subscribe an endpoint to, each mapped to a real billing domain event the app already fires.
 *
 * Deny-by-default: an endpoint can only subscribe to a type in this enum, and the subscriber
 * ({@see WebhookEventSubscriber}) only fans out types it has a payload mapper for.
 * There are NO placeholder types — every case is backed by a genuine source event, listed in its
 * {@see source()}. Engine events come from `Cbox\Billing\Events\*`; the app-level lifecycle
 * moments the engine does not model as events (created/canceled/payment-failed/dunning/coupon/
 * revoke) are dispatched by first-party `App\Webhooks\Events\*` events raised at the real trigger
 * point in the owning service.
 */
enum WebhookEvent: string
{
    case InvoiceIssued = 'invoice.issued';
    case PaymentSettled = 'payment.settled';
    case PaymentFailed = 'payment.failed';
    case CreditNoteIssued = 'credit_note.issued';
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionChanged = 'subscription.changed';
    case SubscriptionRenewed = 'subscription.renewed';
    case SubscriptionCanceled = 'subscription.canceled';
    case SubscriptionCancellationRequested = 'subscription.cancellation_requested';
    case LicenseIssued = 'license.issued';
    case LicenseRevoked = 'license.revoked';
    case CouponRedeemed = 'coupon.redeemed';
    case DunningExhausted = 'dunning.exhausted';

    /** A human label for the console. */
    public function label(): string
    {
        return match ($this) {
            self::InvoiceIssued => 'Invoice issued',
            self::PaymentSettled => 'Payment settled',
            self::PaymentFailed => 'Payment failed',
            self::CreditNoteIssued => 'Credit note issued',
            self::SubscriptionCreated => 'Subscription created',
            self::SubscriptionChanged => 'Subscription changed',
            self::SubscriptionRenewed => 'Subscription renewed',
            self::SubscriptionCanceled => 'Subscription canceled',
            self::SubscriptionCancellationRequested => 'Cancellation requested',
            self::LicenseIssued => 'License issued',
            self::LicenseRevoked => 'License revoked',
            self::CouponRedeemed => 'Coupon redeemed',
            self::DunningExhausted => 'Dunning exhausted',
        };
    }

    /** The console grouping the type belongs to. */
    public function group(): string
    {
        return match ($this) {
            self::InvoiceIssued, self::PaymentSettled, self::PaymentFailed, self::CreditNoteIssued => 'Billing',
            self::SubscriptionCreated, self::SubscriptionChanged, self::SubscriptionRenewed,
            self::SubscriptionCanceled, self::SubscriptionCancellationRequested, self::DunningExhausted => 'Subscriptions',
            self::LicenseIssued, self::LicenseRevoked => 'Licensing',
            self::CouponRedeemed => 'Catalog',
        };
    }

    /**
     * The source domain event that raises this type. Documented so the catalog is auditable and
     * an integrator (and a reviewer) can trace every outbound type back to the code that fires it.
     */
    public function source(): string
    {
        return match ($this) {
            self::InvoiceIssued => 'Cbox\Billing\Events\InvoiceIssued',
            self::PaymentSettled => 'Cbox\Billing\Events\PaymentSettled',
            self::PaymentFailed => 'App\Webhooks\Events\PaymentFailed',
            self::CreditNoteIssued => 'Cbox\Billing\Events\CreditNoteIssued',
            self::SubscriptionCreated => 'App\Webhooks\Events\SubscriptionCreated',
            self::SubscriptionChanged => 'Cbox\Billing\Events\SubscriptionChanged',
            self::SubscriptionRenewed => 'Cbox\Billing\Events\SubscriptionRenewed',
            self::SubscriptionCanceled => 'App\Webhooks\Events\SubscriptionCanceled',
            self::SubscriptionCancellationRequested => 'Cbox\Billing\Retention\Events\SubscriptionCancellationRequested',
            self::LicenseIssued => 'Cbox\Billing\Events\LicenseIssued',
            self::LicenseRevoked => 'App\Webhooks\Events\LicenseRevoked',
            self::CouponRedeemed => 'App\Webhooks\Events\CouponRedeemed',
            self::DunningExhausted => 'App\Webhooks\Events\DunningExhausted',
        };
    }

    /**
     * All catalog types, grouped for the console picker.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $case) {
            $groups[$case->group()][] = $case;
        }

        return $groups;
    }

    /**
     * Validate a caller-supplied list of type strings against the catalog, returning the subset
     * that maps to a real case (deny-by-default drops anything unknown).
     *
     * @param  array<int, mixed>  $types
     * @return list<string>
     */
    public static function sanitize(array $types): array
    {
        $valid = [];

        foreach ($types as $type) {
            if (is_string($type) && self::tryFrom($type) instanceof self) {
                $valid[] = $type;
            }
        }

        return array_values(array_unique($valid));
    }
}
