<?php

declare(strict_types=1);

namespace App\Billing\Api;

use App\Billing\Audit\ValueObjects\AuditActor;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Models\Environment;

/**
 * The resolved identity behind an authenticated API request. An operator identity may
 * act for ANY org; an org-scoped identity may act only for its own org. `mayActFor()` is
 * the deny-by-default gate the middleware/controllers enforce against the `org` in the
 * request body.
 *
 * A token may additionally be bound to ONE product (`productId`): on a shared instance
 * billing several products, such a token sees and sells only that product's plans —
 * catalog reads filter to it and plan resolution refuses other products' keys. An
 * unbound token (null) keeps the legacy whole-catalog behavior.
 *
 * The `environmentKey` is the billing environment (plane) the credential is bound to; the
 * authenticator sets it from the token (falling back to the legacy `mode` for older tokens), and
 * the API middleware resolves it and pushes the {@see Environment} onto the ambient
 * {@see BillingContext} so the request reads/writes only that environment's rows. `mode` is the
 * retained legacy test/live view of the same binding.
 */
readonly class ApiIdentity
{
    public string $environmentKey;

    private function __construct(
        public bool $isOperator,
        public ?string $organizationId,
        public ?int $productId = null,
        public BillingMode $mode = BillingMode::Live,
        public string $actorSub = 'api-token',
        public ?string $actorName = null,
        ?string $environmentKey = null,
    ) {
        // A token minted before the environment binding carries only its mode; derive the plane
        // from it (live → production, test → sandbox) so every existing credential keeps working.
        $this->environmentKey = $environmentKey ?? ($mode->isTest() ? Environment::SANDBOX : Environment::PRODUCTION);
    }

    public static function operator(?int $productId = null, BillingMode $mode = BillingMode::Live, string $actorSub = 'api-token', ?string $actorName = null, ?string $environmentKey = null): self
    {
        return new self(true, null, $productId, $mode, $actorSub, $actorName, $environmentKey);
    }

    public static function forOrganization(string $organizationId, ?int $productId = null, BillingMode $mode = BillingMode::Live, string $actorSub = 'api-token', ?string $actorName = null, ?string $environmentKey = null): self
    {
        return new self(false, $organizationId, $productId, $mode, $actorSub, $actorName, $environmentKey);
    }

    /** The audit actor this API credential attributes its mutations to (the token identity). */
    public function auditActor(?string $ip = null): AuditActor
    {
        return new AuditActor($this->actorSub, $this->actorName, $ip);
    }

    /** Whether this identity is permitted to act for `$org`. */
    public function mayActFor(string $org): bool
    {
        return $this->isOperator || $this->organizationId === $org;
    }

    /** Whether this identity may see/sell a plan belonging to `$productId` (deny cross-product). */
    public function mayUseProduct(int $productId): bool
    {
        return $this->productId === null || $this->productId === $productId;
    }
}
