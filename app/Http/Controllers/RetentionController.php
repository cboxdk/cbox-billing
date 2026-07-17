<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Retention\Contracts\ManagesRetention;
use App\Billing\Retention\Enums\CancellationMode;
use App\Billing\Retention\Exceptions\RetentionException;
use App\Billing\Retention\ValueObjects\CancellationRequest;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console's retention actions over the App-A {@see ManagesRetention} contract: cancel
 * with a captured reason (immediate / period-end / pause-instead-of-cancel) and win-back
 * reactivation. Thin — it validates the operator's input, delegates to the service, and
 * redirects back to the subscription with a flash message; the reason capture, the fork,
 * and the win-back decision all live in the service.
 */
class RetentionController extends Controller
{
    public function cancel(Request $request, Subscription $subscription, ManagesRetention $retention): RedirectResponse
    {
        $request->validate([
            'mode' => ['required', 'in:immediate,period_end,pause'],
            'reason' => ['nullable', 'string', 'max:255'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $mode = CancellationMode::from($request->string('mode')->toString());

        $retention->cancel($subscription, new CancellationRequest(
            mode: $mode,
            reason: $request->filled('reason') ? $request->string('reason')->toString() : null,
            feedback: $request->filled('feedback') ? $request->string('feedback')->toString() : null,
        ));

        return redirect()
            ->route('billing.subscriptions.show', $subscription->id)
            ->with('status', $this->cancelMessage($mode));
    }

    public function reactivate(Subscription $subscription, ManagesRetention $retention): RedirectResponse
    {
        try {
            $retention->reactivate($subscription);
        } catch (RetentionException $e) {
            return redirect()
                ->route('billing.subscriptions.show', $subscription->id)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.subscriptions.show', $subscription->id)
            ->with('status', 'Subscription reactivated.');
    }

    private function cancelMessage(CancellationMode $mode): string
    {
        return match ($mode) {
            CancellationMode::Immediate => 'Subscription canceled immediately.',
            CancellationMode::PeriodEnd => 'Cancellation scheduled for period end.',
            CancellationMode::Pause => 'Subscription paused.',
        };
    }
}
