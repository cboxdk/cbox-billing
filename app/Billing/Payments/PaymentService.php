<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\PaysInvoices;
use App\Models\Invoice;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Illuminate\Support\Str;

/**
 * Charges an invoice through the bound {@see PaymentGateway}. The payment intent is keyed
 * to the invoice's legal number (the reconciliation reference the settlement webhook later
 * carries), so the money movement and the eventual paid effect are joined on one natural
 * key. Thin by design — the invoice's paid state is written only by the exactly-once
 * webhook ingest.
 */
readonly class PaymentService implements PaysInvoices
{
    public function __construct(private PaymentGateway $gateway) {}

    public function pay(Invoice $invoice): PaymentResult
    {
        $intent = new PaymentIntent(
            id: 'pi_'.Str::random(24),
            amount: $invoice->total(),
            reference: $invoice->number,
        );

        return $this->gateway->charge($intent);
    }
}
