<?php

declare(strict_types=1);

namespace App\Billing\Audit\ValueObjects;

/**
 * The result of walking the audit hash chain. `intact` is true only when every row's stored
 * hash recomputes from its predecessor; when it is false, `brokenSequence` is the sequence of
 * the FIRST row that failed to verify (a rewritten row, a re-linked `prev_hash`, or a gap).
 */
readonly class ChainStatus
{
    public function __construct(
        public bool $intact,
        public int $verified,
        public ?int $brokenSequence = null,
        public ?string $reason = null,
    ) {}

    /** An intact chain of `$verified` rows (a `$verified` of 0 is an empty, trivially-intact chain). */
    public static function ok(int $verified): self
    {
        return new self(true, $verified, null, null);
    }

    /** A chain that broke at `$sequence` after `$verified` good rows, with a human reason. */
    public static function broken(int $verified, int $sequence, string $reason): self
    {
        return new self(false, $verified, $sequence, $reason);
    }

    public function summary(): string
    {
        if ($this->intact) {
            return sprintf('Chain intact — %d event(s) verified.', $this->verified);
        }

        return sprintf(
            'Chain BROKEN at sequence %d after %d good event(s): %s',
            $this->brokenSequence,
            $this->verified,
            $this->reason ?? 'hash mismatch',
        );
    }
}
