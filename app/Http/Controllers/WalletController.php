<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Approvals\ApprovableActionRegistry;
use App\Billing\Approvals\ApprovalGate;
use App\Billing\Approvals\Enums\ApprovalActionType;
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
    public function adjust(Request $request, Organization $organization, ApprovableActionRegistry $registry, ApprovalGate $gate): RedirectResponse
    {
        $request->validate([
            'direction' => ['required', 'in:grant,debit'],
            'pool' => ['required', 'in:promotional,purchased,included'],
            'denomination' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'integer', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $reason = $request->string('reason')->toString();

        // The held action captures the maker as `actor`, so the wallet row records who
        // ORIGINATED the adjustment even when a different operator approves it. Below the
        // configured threshold (or disabled) it applies immediately, exactly as before.
        $action = $registry->build(ApprovalActionType::WalletAdjust, [
            'organization_id' => $organization->id,
            'direction' => $request->string('direction')->toString(),
            'pool' => $request->string('pool')->toString(),
            'denomination' => $request->string('denomination')->toString(),
            'amount' => $request->integer('amount'),
            'reason' => $reason,
            'actor' => $this->actor($request),
            'expires_in_days' => $request->filled('expires_in_days') ? $request->integer('expires_in_days') : null,
        ]);

        try {
            $result = $gate->run($action, $reason);
        } catch (WalletActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', $result->wasHeld()
                ? sprintf('Wallet adjustment submitted for approval (request #%d) — not applied yet.', $result->request?->id)
                : ($result->outcome !== null ? $result->outcome->summary : 'Wallet adjusted.'));
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
