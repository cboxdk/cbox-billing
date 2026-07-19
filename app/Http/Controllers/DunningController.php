<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Payments\Contracts\RetriesPayments;
use App\Models\PaymentRetry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual dunning controls (Wave 3) — thin HTTP over {@see RetriesPayments}. "Retry now"
 * brings the next smart-retry attempt due and runs it through the engine (idempotent per
 * (invoice, attempt)); "stop dunning" halts the schedule with the terminal-action choice
 * (cancel now, or leave past due). Both mutating → `subscriptions:manage`.
 */
class DunningController extends Controller
{
    public function retry(PaymentRetry $retry, RetriesPayments $retries): RedirectResponse
    {
        if (! $retry->isRetrying()) {
            return back()->with('error', 'This dunning schedule is no longer active.');
        }

        $retries->retryNow($retry);

        return back()->with('status', 'Retry attempted — the charge was re-run through the gateway.');
    }

    public function stop(Request $request, PaymentRetry $retry, RetriesPayments $retries): RedirectResponse
    {
        $request->validate(['terminal' => ['required', 'in:cancel,keep']]);

        if (! $retry->isRetrying()) {
            return back()->with('error', 'This dunning schedule is no longer active.');
        }

        $cancel = $request->string('terminal')->toString() === 'cancel';
        $retries->stop($retry, $cancel);

        return back()->with('status', $cancel
            ? 'Dunning stopped and the subscription canceled.'
            : 'Dunning stopped — the subscription is left past due.');
    }
}
