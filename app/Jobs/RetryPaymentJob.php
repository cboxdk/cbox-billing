<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Payments\Contracts\RetriesPayments;
use App\Models\PaymentRetry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one invoice's due smart-retry attempt via {@see RetriesPayments}. The per-invoice
 * unit the `billing:retry-payments` pass dispatches, so one account's charge failure
 * retries in isolation. The attempt is idempotent per (invoice, attempt) — a re-dispatch
 * that finds the slot already fired is a no-op.
 */
class RetryPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $paymentRetryId) {}

    public function handle(RetriesPayments $retries): void
    {
        $retry = PaymentRetry::query()->find($this->paymentRetryId);

        if (! $retry instanceof PaymentRetry) {
            return;
        }

        $retries->attempt($retry);
    }
}
