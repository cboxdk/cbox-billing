<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RetryPaymentJob;
use App\Models\PaymentRetry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The smart-retry dunning pass: dispatch one queued {@see RetryPaymentJob} per invoice
 * whose next backoff attempt has come due. A thin dispatcher — the per-invoice work
 * (re-charge the gateway, recover the subscription on success or advance / exhaust the
 * schedule on failure) runs in the job, isolated so one account's failure retries alone.
 */
class RetryPayments extends Command
{
    protected $signature = 'billing:retry-payments';

    protected $description = 'Dispatch smart-retry jobs for renewal charges whose next backoff attempt is due.';

    public function handle(): int
    {
        $now = Carbon::now();
        $dispatched = 0;

        $due = PaymentRetry::query()
            ->where('status', PaymentRetry::STATUS_RETRYING)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        foreach ($due as $id) {
            if (! is_int($id)) {
                continue;
            }

            RetryPaymentJob::dispatch($id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d payment-retry job%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
