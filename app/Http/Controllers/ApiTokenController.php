<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
 * it (a confirmed, soft revoke that stops it authenticating immediately). Gated
 * `settings:manage`. Thin over the {@see ApiToken} model's own `issue()`/`revoke()`.
 */
class ApiTokenController extends Controller
{
    public function create(): View
    {
        return view('billing.settings.api-token-form', [
            'activeArea' => 'settings',
            'activeNav' => 'tokens',
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'key', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization_id' => ['nullable', 'string', 'exists:organizations,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $organizationId = $request->filled('organization_id') ? $request->string('organization_id')->toString() : null;
        $productId = $request->filled('product_id') ? $request->integer('product_id') : null;

        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::issue(
            $request->string('name')->toString(),
            $organizationId,
            $productId,
        );

        // Show the plaintext ONCE via a one-shot flash (copybox on the settings page), like the
        // minted license artifact. Only the hash is persisted — it can never be shown again.
        return redirect()
            ->route('billing.settings', ['tab' => 'tokens'])
            ->with('minted_token', [
                'name' => $token->name,
                'scope' => $token->organization_id ?? 'operator',
                'plaintext' => $plaintext,
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
