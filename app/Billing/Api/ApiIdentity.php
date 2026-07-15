<?php

declare(strict_types=1);

namespace App\Billing\Api;

/**
 * The resolved identity behind an authenticated API request. An operator identity may
 * act for ANY org; an org-scoped identity may act only for its own org. `mayActFor()` is
 * the deny-by-default gate the middleware/controllers enforce against the `org` in the
 * request body.
 */
readonly class ApiIdentity
{
    private function __construct(
        public bool $isOperator,
        public ?string $organizationId,
    ) {}

    public static function operator(): self
    {
        return new self(true, null);
    }

    public static function forOrganization(string $organizationId): self
    {
        return new self(false, $organizationId);
    }

    /** Whether this identity is permitted to act for `$org`. */
    public function mayActFor(string $org): bool
    {
        return $this->isOperator || $this->organizationId === $org;
    }
}
