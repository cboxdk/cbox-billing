<?php

declare(strict_types=1);

namespace App\Billing\Seats\ValueObjects;

/**
 * The seat picture for an organization: how many Full seats are purchased (the billed
 * quantity), how many are assigned to specific members, how many remain free, and the
 * member lists split into Full (assigned) and Light (eligible-but-unassigned, free).
 *
 * Purchased Full seats are the only billing driver; Light members are counted and
 * displayed but never billed. The type labels/flags come from `config('billing.seats')`
 * so a priced Light tier is expressible later without reshaping this projection.
 *
 * @phpstan-type SeatMember array{subject: string, role: string, source?: string, assigned_at?: string|null}
 */
readonly class SeatBreakdown
{
    /**
     * @param  list<SeatMember>  $full  Assigned members (each holds one purchased Full seat).
     * @param  list<SeatMember>  $light  Eligible members without a seat (free).
     * @param  list<SeatMember>  $assignable  Eligible-but-unassigned members the console offers to assign.
     */
    public function __construct(
        public int $purchased,
        public int $assigned,
        public array $full,
        public array $light,
        public array $assignable,
    ) {}

    /** Free purchased seats available to assign (never negative). */
    public function free(): int
    {
        return max(0, $this->purchased - $this->assigned);
    }

    /** Full members = assigned count (billed). */
    public function fullCount(): int
    {
        return count($this->full);
    }

    /** Light members = eligible-but-unassigned (free, never billed). */
    public function lightCount(): int
    {
        return count($this->light);
    }
}
