<?php

declare(strict_types=1);

namespace App\Billing\Api;

use App\Billing\Api\Contracts\ApiTokenAuthenticator;
use App\Models\ApiToken;
use Illuminate\Support\Carbon;

/**
 * The default token authenticator. It recognises two credentials, in order:
 *
 *  1. A configured operator token (`billing.api.static_token`) — resolves to an operator
 *     identity that may act for any org. Compared in constant time.
 *  2. A per-org `api_tokens` row, matched on the SHA-256 of the presented token and not
 *     revoked — resolves to that org's identity (or an operator identity when the row is
 *     unscoped). A revoked row (`revoked_at` set) authenticates nothing.
 *
 * Anything else authenticates nothing and returns `null` (deny-by-default).
 */
readonly class DatabaseApiTokenAuthenticator implements ApiTokenAuthenticator
{
    /**
     * @param  int  $lastUsedThrottleSeconds  don't rewrite `last_used_at` if it was stamped
     *                                        within this many seconds — throttles a write (and
     *                                        its row lock) on every authenticated hot-path call
     */
    public function __construct(
        private ?string $staticToken,
        private int $lastUsedThrottleSeconds = 300,
    ) {}

    public function authenticate(string $bearer): ?ApiIdentity
    {
        if ($bearer === '') {
            return null;
        }

        if ($this->staticToken !== null && $this->staticToken !== '' && hash_equals($this->staticToken, $bearer)) {
            return ApiIdentity::operator(actorSub: 'api-token:static', actorName: 'Static operator token');
        }

        $token = ApiToken::query()
            ->where('hash', hash('sha256', $bearer))
            ->whereNull('revoked_at')
            ->first();

        if (! $token instanceof ApiToken) {
            return null;
        }

        $this->touchLastUsed($token);

        $productId = $token->product_id !== null ? (int) $token->product_id : null;
        $mode = $token->billingMode();
        $actorSub = 'api-token:'.$token->id;
        $actorName = $token->name;

        return $token->organization_id === null
            ? ApiIdentity::operator($productId, $mode, $actorSub, $actorName)
            : ApiIdentity::forOrganization($token->organization_id, $productId, $mode, $actorSub, $actorName);
    }

    /**
     * Stamp `last_used_at` — but skip the write when it was last stamped within the throttle
     * window (PERF-5). `last_used_at` is a coarse "recently seen" signal, not an audit log, so
     * on the SDK hot path a per-call UPDATE (and its row lock) is pure write/lock load for no
     * information; throttling to once per window keeps the signal without the churn.
     */
    private function touchLastUsed(ApiToken $token): void
    {
        $now = Carbon::now();
        $lastUsed = $token->last_used_at;

        if ($lastUsed !== null && $lastUsed->gt($now->copy()->subSeconds($this->lastUsedThrottleSeconds))) {
            return;
        }

        $token->forceFill(['last_used_at' => $now])->save();
    }
}
