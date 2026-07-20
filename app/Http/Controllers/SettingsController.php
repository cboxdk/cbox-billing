<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Environments\Gateways\GatewayCredentialException;
use App\Billing\Mode\BillingContext;
use App\Billing\Reporting\SettingsReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The platform-settings gateway page. Payment gateways now carry PER-ENVIRONMENT, DB-backed,
 * encrypted credentials (Stripe secret / publishable / webhook-signing secret) for the active
 * plane, on top of the legacy global env-var status. Entering keys for a sandbox is gated to TEST
 * keys and production to LIVE keys (the safety gate — see {@see EnvironmentGatewayStore}), so a
 * real card can never be charged in a sandbox. The env-var status remains the BC fallback for a
 * plane with no DB keys. Reads carry `settings:read`, the per-environment save `settings:manage`.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly BillingContext $context) {}

    public function gateways(SettingsReport $report, EnvironmentGatewayStore $store): View
    {
        $environment = $this->context->environment();
        $credentials = $store->forEnvironment($environment->key);

        return view('billing.settings.gateways', [
            'activeArea' => 'settings',
            'activeNav' => 'gateways',
            'gateways' => $report->gatewayGuidance(),
            'environment' => [
                'key' => $environment->key,
                'name' => $environment->name,
                'gateway_key_mode' => $environment->gateway_key_mode->value,
                'is_production' => $environment->isProduction(),
            ],
            // Never surface the stored secrets — only whether each is set + its public publishable key.
            'stripe' => [
                'configured' => $credentials !== null,
                'active' => $credentials !== null && $credentials->active,
                'publishable' => $credentials?->publishable,
                'has_webhook_secret' => $credentials !== null && ($credentials->webhook_secret ?? '') !== '',
            ],
        ]);
    }

    /** Save the active environment's Stripe credentials, key-type-gated to the plane's mode. */
    public function storeGateway(Request $request, EnvironmentGatewayStore $store): RedirectResponse
    {
        $request->validate([
            'secret' => ['required', 'string', 'max:255'],
            'publishable' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        $environment = $this->context->environment();

        try {
            $store->put(
                $environment,
                $request->string('secret')->toString(),
                $request->filled('publishable') ? $request->string('publishable')->toString() : null,
                $request->filled('webhook_secret') ? $request->string('webhook_secret')->toString() : null,
                $request->boolean('active', true),
            );
        } catch (GatewayCredentialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.settings.gateways')->with('status', sprintf(
            'Saved Stripe credentials for “%s” (%s keys).',
            $environment->key,
            $environment->gateway_key_mode->value,
        ));
    }
}
