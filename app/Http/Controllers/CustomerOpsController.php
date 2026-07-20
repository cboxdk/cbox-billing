<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Approvals\ApprovableActionRegistry;
use App\Billing\Approvals\ApprovalGate;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Models\Organization;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Operator organization management (Wave 4) — thin HTTP over the org model, the engine
 * account-standing seam and the payment gateway. Suspend / reactivate flip BOTH the app's
 * `suspended_at` mirror AND the engine account standing; a profile edit persists the org
 * fields but refuses a billing-currency change once the account has transacted (the one-way
 * currency lock); the payment-method actions proxy the bound gateway (set-default / detach).
 * Org writes are gated `customers:manage`; payment-method writes `payments:manage`.
 */
class CustomerOpsController extends Controller
{
    public function suspend(Organization $organization, ApprovableActionRegistry $registry, ApprovalGate $gate): RedirectResponse
    {
        // Suspension is routed through the approval gate: below/disabled it suspends immediately
        // (flipping the app mirror + engine standing + audit, exactly as before); when the
        // policy requires it, the suspension is held for a second operator and does not apply.
        $action = $registry->build(ApprovalActionType::CustomerSuspend, [
            'organization_id' => $organization->id,
        ]);

        $result = $gate->run($action, 'Operator-initiated suspension');

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', $result->wasHeld()
                ? sprintf('Suspension of %s submitted for approval (request #%d) — not applied yet.', $organization->name, $result->request?->id)
                : ($result->outcome !== null ? $result->outcome->summary : sprintf('%s suspended.', $organization->name)));
    }

    public function reactivate(Organization $organization, AccountStanding $standing, RecordsAudit $audit): RedirectResponse
    {
        $wasSuspended = $organization->suspended_at !== null;
        $organization->forceFill(['suspended_at' => null])->save();
        $standing->flag($organization->id, AccountStandingState::Good, 'Reactivated by operator from the console.');

        $audit->record(
            AuditAction::CustomerReactivated,
            AuditTarget::of('organization', $organization->id, $organization->id),
            sprintf('Reactivated organization %s.', $organization->id),
            [
                'before' => ['suspended' => $wasSuspended, 'standing' => AccountStandingState::Suspended->value],
                'after' => ['suspended' => false, 'standing' => AccountStandingState::Good->value],
            ],
        );

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', sprintf('%s reactivated.', $organization->name));
    }

    public function updateProfile(Request $request, Organization $organization, BillingCurrencyLock $currencyLock): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'billing_email' => ['nullable', 'email', 'max:190'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'billing_currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);

        $requestedCurrency = $request->filled('billing_currency')
            ? strtoupper($request->string('billing_currency')->toString())
            : $organization->billing_currency;

        // The currency is one-way locked once the account transacts — refuse a change that
        // would break the grandfathered lock, keeping the current currency instead.
        $locked = $currencyLock->lockedCurrency($organization->id) !== null || $organization->invoices()->exists();

        if ($locked && $requestedCurrency !== $organization->billing_currency && $organization->billing_currency !== null) {
            return back()->withInput()->with('error', sprintf(
                'The billing currency is locked to %s — it cannot change once the account has transacted.',
                $organization->billing_currency,
            ));
        }

        $organization->forceFill([
            'name' => $request->string('name')->toString(),
            'billing_email' => $request->filled('billing_email') ? $request->string('billing_email')->toString() : null,
            'tax_id' => $request->filled('tax_id') ? $request->string('tax_id')->toString() : null,
            'billing_currency' => $requestedCurrency,
        ])->save();

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', 'Organization profile updated.');
    }

    public function setDefaultPaymentMethod(Request $request, Organization $organization, PaymentGateway $gateway, ResolvesGatewayCustomer $customers): RedirectResponse
    {
        $request->validate(['id' => ['required', 'string']]);

        try {
            $gateway->setDefaultPaymentMethod($customers->resolve($organization), $request->string('id')->toString());
        } catch (Throwable $e) {
            return back()->with('error', 'Could not set the default payment method: '.$e->getMessage());
        }

        return back()->with('status', 'Default payment method updated.');
    }

    public function removePaymentMethod(Request $request, Organization $organization, PaymentGateway $gateway, ResolvesGatewayCustomer $customers): RedirectResponse
    {
        $request->validate(['id' => ['required', 'string']]);

        try {
            $gateway->detachPaymentMethod($customers->resolve($organization), $request->string('id')->toString());
        } catch (Throwable $e) {
            return back()->with('error', 'Could not remove the payment method: '.$e->getMessage());
        }

        return back()->with('status', 'Payment method removed.');
    }
}
