<?php

declare(strict_types=1);

namespace App\Billing\Retirement\ValueObjects;

/**
 * The customer-facing sunset notice for a subscription on a retiring plan (ADR-0016) — the
 * value the portal and the console render. It states the plan's cutoff, the subscriber's
 * renewal-due deadline (the next renewal on/after the cutoff, by which they must choose),
 * and the default they fall to if they do nothing; plus the three first-class choices the
 * subscriber has — pick a successor, cancel, or do nothing → the default.
 *
 *  - `$election` is what the subscriber has already chosen: `successor` (a scheduled plan
 *    change), `cancel` (a scheduled period-end cancel), or `none` (undecided).
 *  - `$successors` is the pick-list of plans they may move onto, each `{key, name, price}`.
 *  - `$unresolved` is the deny-by-default terminal case: the cutoff has passed with no
 *    choice and no default — the subscriber is blocked from renewing, surfaced to ops.
 */
readonly class SunsetNotice
{
    /**
     * @param  list<array{key: string, name: string, price: string}>  $successors
     */
    public function __construct(
        public string $planName,
        public string $retiresAt,
        public string $renewalDue,
        public ?string $defaultSuccessorKey,
        public ?string $defaultSuccessorName,
        public string $election,
        public ?string $electedSuccessorName,
        public array $successors,
        public bool $unresolved = false,
    ) {}

    public function hasDefault(): bool
    {
        return $this->defaultSuccessorKey !== null;
    }

    public function isUndecided(): bool
    {
        return $this->election === 'none';
    }
}
