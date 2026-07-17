<?php

declare(strict_types=1);

namespace App\Billing\Api;

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
 */
readonly class ApiIdentity
{
    private function __construct(
        public bool $isOperator,
        public ?string $organizationId,
        public ?int $productId = null,
    ) {}

    public static function operator(?int $productId = null): self
    {
        return new self(true, null, $productId);
    }

    public static function forOrganization(string $organizationId, ?int $productId = null): self
    {
        return new self(false, $organizationId, $productId);
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
