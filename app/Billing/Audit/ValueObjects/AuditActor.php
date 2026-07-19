<?php

declare(strict_types=1);

namespace App\Billing\Audit\ValueObjects;

/**
 * Who performed an audited action: the operator's cross-product subject (`sub`), their display
 * name, and the request IP. A system/scheduled action carries the sentinel {@see system()}
 * actor — an unattended run is recorded honestly as `system`, never as a fabricated operator.
 */
readonly class AuditActor
{
    public const SYSTEM_SUB = 'system';

    public function __construct(
        public string $sub,
        public ?string $name = null,
        public ?string $ip = null,
    ) {}

    /** The sentinel actor for a scheduled/system action with no interactive operator. */
    public static function system(): self
    {
        return new self(self::SYSTEM_SUB, 'System', null);
    }

    public function isSystem(): bool
    {
        return $this->sub === self::SYSTEM_SUB;
    }
}
