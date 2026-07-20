<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Features\OrganizationFeatureOverrides;
use App\Models\Feature;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Org-level feature overrides — thin HTTP over {@see OrganizationFeatureOverrides}. Reachable from
 * the customer detail page: an operator can grant a feature the org's plan doesn't, revoke one it
 * does, or clear the override to restore the plan-resolved value. Every write is audit-logged by
 * the service. Gated `customers:manage`.
 */
class OrganizationFeatureOverrideController extends Controller
{
    public function override(Request $request, Organization $organization, OrganizationFeatureOverrides $overrides): RedirectResponse
    {
        $request->validate([
            'feature_id' => ['required', 'integer', 'exists:features,id'],
            'direction' => ['required', 'in:grant,revoke'],
            'value' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $feature = Feature::query()->findOrFail($request->integer('feature_id'));
        $granted = $request->string('direction')->toString() === 'grant';

        $overrides->set(
            $organization->id,
            $feature,
            $granted,
            $request->filled('value') ? $request->string('value')->toString() : null,
            $request->filled('reason') ? $request->string('reason')->toString() : null,
            $this->actor($request),
        );

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', sprintf(
                'Feature “%s” %s for this organization.',
                $feature->key,
                $granted ? 'granted' : 'revoked',
            ));
    }

    public function clear(Request $request, Organization $organization, OrganizationFeatureOverrides $overrides): RedirectResponse
    {
        $request->validate([
            'feature_id' => ['required', 'integer', 'exists:features,id'],
        ]);

        $feature = Feature::query()->findOrFail($request->integer('feature_id'));

        $overrides->clear($organization->id, $feature, $this->actor($request));

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', sprintf('Cleared the “%s” override — the plan-resolved value applies.', $feature->key));
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
