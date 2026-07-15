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
 *  2. A per-org `api_tokens` row, matched on the SHA-256 of the presented token — resolves
 *     to that org's identity (or an operator identity when the row is unscoped).
 *
 * Anything else authenticates nothing and returns `null` (deny-by-default).
 */
readonly class DatabaseApiTokenAuthenticator implements ApiTokenAuthenticator
{
    public function __construct(private ?string $staticToken) {}

    public function authenticate(string $bearer): ?ApiIdentity
    {
        if ($bearer === '') {
            return null;
        }

        if ($this->staticToken !== null && $this->staticToken !== '' && hash_equals($this->staticToken, $bearer)) {
            return ApiIdentity::operator();
        }

        $token = ApiToken::query()->where('hash', hash('sha256', $bearer))->first();

        if (! $token instanceof ApiToken) {
            return null;
        }

        $token->forceFill(['last_used_at' => Carbon::now()])->save();

        return $token->organization_id === null
            ? ApiIdentity::operator()
            : ApiIdentity::forOrganization($token->organization_id);
    }
}
