<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Seats\Exceptions\SeatException;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The console's seat actions over the {@see ManagesSeats} contract: buy/release purchased
 * Full seats (the billed quantity, through the engine's prorated changeQuantity) and
 * assign/unassign a purchased seat to a specific eligible member. Thin — it validates the
 * operator's input, delegates to the service, and redirects back to the subscription with a
 * flash message; the invariant (assigned ≤ purchased) and the billing all live in the
 * service. A refused invariant surfaces as an error flash.
 */
class SeatController extends Controller
{
    /** Set the purchased Full-seat count (buy/release) — guardrailed against the assigned count. */
    public function setPurchased(Request $request, Subscription $subscription, ManagesSeats $seats): RedirectResponse
    {
        $request->validate(['seats' => ['required', 'integer', 'min:1']]);

        try {
            $seats->setPurchased($subscription, $request->integer('seats'));
        } catch (SeatException $e) {
            return $this->back($subscription, error: $e->getMessage());
        }

        return $this->back($subscription, status: 'Purchased seats updated.');
    }

    /** Assign a free purchased seat to an eligible member (they become Full). */
    public function assign(Request $request, Subscription $subscription, ManagesSeats $seats): RedirectResponse
    {
        $request->validate(['subject' => ['required', 'string']]);

        try {
            $seats->assign($subscription, $request->string('subject')->toString());
        } catch (SeatException $e) {
            return $this->back($subscription, error: $e->getMessage());
        }

        return $this->back($subscription, status: 'Seat assigned.');
    }

    /** Free a member's seat (they become Light); the purchased count is unchanged. */
    public function unassign(Request $request, Subscription $subscription, ManagesSeats $seats): RedirectResponse
    {
        $request->validate(['subject' => ['required', 'string']]);

        $seats->unassign($subscription->organization_id, $request->string('subject')->toString());

        return $this->back($subscription, status: 'Seat released.');
    }

    private function back(Subscription $subscription, ?string $status = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route('billing.subscriptions.show', $subscription->id);

        return $error !== null ? $redirect->with('error', $error) : $redirect->with('status', $status);
    }
}
