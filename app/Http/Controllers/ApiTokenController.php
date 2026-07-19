<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Billing\Mode\BillingMode;
use App\Billing\Reporting\SettingsReport;
use App\Http\Middleware\EnsureOperator;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Self-service API-token authoring (Wave 4) — the console equivalent of the `billing:token`
 * CLI. An operator mints a bearer token for the management/enforcement API (scoped operator,
 * org, or product) and the plaintext is shown ONCE (only its SHA-256 is stored), then revokes
 * it (a confirmed, soft revoke that stops it authenticating immediately).
 *
 * An operator-scoped (org-null) token acts for ANY org — the cross-tenant takeover vector
 * (SEC-1). It is now doubly protected: the whole console is behind the operator-org gate
 * ({@see EnsureOperator}), so only a verified operator reaches this
 * surface at all, and the mint records the minting operator's subject for an audit trail.
 * Gated `settings:manage`. Thin over the {@see ApiToken} model's own `issue()`/`revoke()`.
 */
class ApiTokenController extends Controller
{
    public function __construct(private readonly CurrentUser $current) {}

    public function create(): View
    {
        return view('billing.settings.api-token-form', [
            'activeArea' => 'settings',
            'activeNav' => 'tokens',
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'key', 'name']),
        ]);
    }

    public function store(Request $request, SettingsReport $report): View
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization_id' => ['nullable', 'string', 'exists:organizations,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'mode' => ['nullable', 'string', 'in:live,test'],
        ]);

        $organizationId = $request->filled('organization_id') ? $request->string('organization_id')->toString() : null;
        $productId = $request->filled('product_id') ? $request->integer('product_id') : null;
        $mode = BillingMode::parse($request->string('mode')->toString());

        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::issue(
            $request->string('name')->toString(),
            $organizationId,
            $productId,
            $this->current->user()?->sub,
            $mode,
        );

        // Show the plaintext ONCE by RENDERING it directly into this POST response (SEC-3) —
        // never flashing it through the session store, where a persistent driver with
        // SESSION_ENCRYPT=false would write the secret to disk unencrypted. Only the hash is
        // persisted; a page refresh (a fresh GET) will never show it again.
        return view('billing.settings', [
            'activeArea' => 'settings',
            'activeNav' => 'tokens',
            'tab' => 'tokens',
            'sellers' => $report->sellers(),
            'taxRegistrations' => $report->taxRegistrations(),
            'gateways' => $report->gateways(),
            'apiTokens' => $report->apiTokens(),
            'webhook' => $report->webhook(),
            'webhookReceivers' => $report->webhookReceivers(),
            'minted' => [
                'name' => $token->name,
                'scope' => $token->organization_id ?? 'operator',
                'mode' => $token->mode,
                'plaintext' => $plaintext,
            ],
        ]);
    }

    public function revoke(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->revoke();

        return redirect()
            ->route('billing.settings', ['tab' => 'tokens'])
            ->with('status', sprintf('API token “%s” revoked — it can no longer authenticate.', $apiToken->name));
    }
}
