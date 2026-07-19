<?php

declare(strict_types=1);

namespace App\Billing\Payments\Dunning;

/**
 * The outcome of applying a {@see CardUpdate}: which account it resolved to, whether the
 * vaulted default was updated, how many in-dunning retries were re-attempted immediately, and
 * how many of those recovered. Returned to the webhook controller (for the response/log) and
 * asserted in tests. A no-op / denied update resolves to `applied=false`.
 */
readonly class CardUpdateResult
{
    public function __construct(
        public bool $applied,
        public ?string $organizationId,
        public int $reattempted,
        public int $recovered,
        public string $reason,
    ) {}

    public static function ignored(string $reason): self
    {
        return new self(false, null, 0, 0, $reason);
    }

    public static function applied(string $organizationId, int $reattempted, int $recovered): self
    {
        return new self(true, $organizationId, $reattempted, $recovered, 'applied');
    }
}
