<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\PaysInvoices;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Models\Invoice;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Illuminate\Support\Str;
use Throwable;

/**
 * Charges an invoice through the bound {@see PaymentGateway}. The payment intent is keyed
 * to the invoice's legal number (the reconciliation reference the settlement webhook later
 * carries), so the money movement and the eventual paid effect are joined on one natural
 * key. Thin by design — the invoice's paid state is written only by the exactly-once
 * webhook ingest.
 *
 * OFF-SESSION. This is the renewal/dunning path: nobody is at the keyboard, so the intent must
 * name the gateway customer AND the vaulted instrument to charge. It previously named neither,
 * so the gateway created an intent with no payment method attached — which Stripe answers
 * `requires_payment_method`. That maps to "needs action" rather than "failed", and
 * {@see PaymentRetryService::chargeRenewal()} only opens dunning on a failure: the subscription
 * renewed, collected nothing, and reported no error. An account with no saved method now
 * returns a FAILED result, which is the recoverable outcome precisely because it opens dunning.
 */
readonly class PaymentService implements PaysInvoices
{
    public function __construct(
        private PaymentGateway $gateway,
        private ResolvesGatewayCustomer $customers,
    ) {}

    public function pay(Invoice $invoice): PaymentResult
    {
        $organization = $invoice->organization;

        if ($organization === null) {
            return PaymentResult::failed('The invoice has no organization to charge.');
        }

        // A GATEWAY ERROR MUST DEGRADE TO "failed", NOT ESCAPE.
        //
        // Both calls below talk to the gateway and both can throw: resolve() may mint a
        // customer, and paymentMethods() wraps any Throwable from the SDK. Nothing further up
        // catches — not chargeRenewal(), not RenewSubscriptionJob — so an unhandled throw kills
        // the renewal job AFTER the period was advanced and the invoice issued: the invoice
        // sits Open, dunning never opens, and the subscription keeps serving.
        //
        // charge() itself already catches for exactly this reason. A brief gateway incident
        // during the monthly run must land in dunning, which is recoverable, rather than in
        // failed_jobs, which nobody is watching.
        try {
            $account = $this->customers->resolve($organization);
        } catch (Throwable $e) {
            return PaymentResult::failed('Could not resolve the gateway customer: '.$e->getMessage());
        }

        $intent = new PaymentIntent(
            id: 'pi_'.Str::random(24),
            amount: $invoice->total(),
            reference: $invoice->number,
            customerRef: $account,
            paymentMethodRef: $this->defaultMethodFor($account),
        );

        return $this->gateway->charge($intent);
    }

    /**
     * The account's default vaulted instrument, or null when it has none.
     *
     * The gateway flags exactly one method as the default. Falling back to the first saved
     * method would charge an instrument the customer never nominated, so an account with
     * saved-but-undefaulted methods is treated as having none and fails into dunning, which
     * asks the customer to choose.
     */
    private function defaultMethodFor(string $account): ?string
    {
        try {
            $methods = $this->gateway->paymentMethods($account);
        } catch (Throwable) {
            // Unreachable-instrument and no-instrument are the same decision here: the intent
            // is not off-session, charge() refuses it, and dunning opens. Guessing an id from a
            // failed lookup would attempt a charge against an instrument we could not confirm.
            return null;
        }

        foreach ($methods as $method) {
            if ($method->isDefault) {
                return $method->id;
            }
        }

        return null;
    }
}
