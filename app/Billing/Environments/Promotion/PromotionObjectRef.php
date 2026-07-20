<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion;

/**
 * A single config object named for promotion by its TYPE and stable NATURAL KEY — e.g.
 * `plan:pro`, `seller:acme-inc`, `coupon:WELCOME`. This is the addressable unit of a
 * fine-grained selection: an operator can promote a whole {@see PromotionGroup} or just a
 * handful of individual objects, and both resolve to the same matching-by-natural-key engine.
 *
 * The `type` is a top-level object type slug (see {@see ConfigSurface}); the `key` is that
 * object's natural key in the SOURCE plane (a plan `key`, a coupon `code`, a seller's natural
 * id, `event_type|locale|seller` for a mail template, …).
 */
readonly class PromotionObjectRef
{
    public function __construct(
        public string $type,
        public string $key,
    ) {}

    /**
     * Parse a `type:key` token (the console/CLI form). The key may itself contain colons — a
     * mail template's `event_type|locale|seller` never does, but we split on the FIRST colon
     * only so any future composite key survives. Returns null for a malformed token.
     */
    public static function parse(string $token): ?self
    {
        $token = trim($token);

        if ($token === '' || ! str_contains($token, ':')) {
            return null;
        }

        [$type, $key] = explode(':', $token, 2);
        $type = trim($type);
        $key = trim($key);

        if ($type === '' || $key === '') {
            return null;
        }

        return new self($type, $key);
    }

    /** The stable `type:key` token this ref renders to. */
    public function token(): string
    {
        return $this->type.':'.$this->key;
    }
}
