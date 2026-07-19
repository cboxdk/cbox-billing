<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Wallet\Contracts\AdjustsWallet;
use App\Billing\Wallet\Exceptions\WalletActionDenied;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Operator wallet-credit adjustments (Wave 3) — thin HTTP over {@see AdjustsWallet}. A
 * grant (promotional/goodwill) or a debit (correction) writes through the engine wallet
 * and records an audit row; the guardrails (positive amount, no debt beyond policy) are
 * enforced in the service and flashed back. Gated by `customers:manage`.
 */
class WalletController extends Controller
{
    public function adjust(Request $request, Organization $organization, AdjustsWallet $wallet): RedirectResponse
    {
        $request->validate([
            'direction' => ['required', 'in:grant,debit'],
            'pool' => ['required', 'in:promotional,purchased,included'],
            'denomination' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'integer', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $actor = $this->actor($request);
        $pool = $request->string('pool')->toString();
        $denomination = $request->string('denomination')->toString();
        $amount = $request->integer('amount');
        $reason = $request->string('reason')->toString();

        try {
            if ($request->string('direction')->toString() === 'grant') {
                $wallet->grant($organization->id, $pool, $denomination, $amount, $reason, $actor, $request->filled('expires_in_days') ? $request->integer('expires_in_days') : null);
                $message = sprintf('Granted %d %s to the %s pool.', $amount, $denomination, $pool);
            } else {
                $wallet->debit($organization->id, $pool, $denomination, $amount, $reason, $actor);
                $message = sprintf('Debited %d %s from the %s pool.', $amount, $denomination, $pool);
            }
        } catch (WalletActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', $message);
    }

    /** The signed-in operator, for the audit row; falls back to null when unresolved. */
    private function actor(Request $request): ?string
    {
        $user = $request->session()->get('auth.user');

        if (is_array($user)) {
            $email = $user['email'] ?? $user['sub'] ?? null;

            return is_string($email) ? $email : null;
        }

        return null;
    }
}
