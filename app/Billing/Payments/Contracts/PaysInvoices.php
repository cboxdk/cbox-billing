<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use App\Models\Invoice;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * Initiates collection of an issued invoice through the engine's gateway-agnostic
 * {@see PaymentGateway}. With the manual gateway the
 * charge is recorded as pending and settlement arrives out of band as a webhook; an
 * SDK-backed gateway may settle synchronously. The invoice is only marked paid by the
 * webhook ingest, never here.
 */
interface PaysInvoices
{
    public function pay(Invoice $invoice): PaymentResult;
}
