<?php

declare(strict_types=1);

namespace App\Billing\Seats\Exceptions;

use RuntimeException;

/**
 * A seat operation was refused because it would break the seat invariants: assigning with
 * no free purchased seat, releasing a purchased seat below the assigned count, assigning a
 * subject that is not an eligible member, or dropping the purchased count below one. The
 * message is operator-facing (surfaced as a flash notice in the console and a 409 in the
 * API).
 */
class SeatException extends RuntimeException
{
    public static function noFreeSeat(int $purchased, int $assigned): self
    {
        return new self(sprintf(
            'No free seat to assign: %d of %d purchased seats are already assigned. Buy more seats first.',
            $assigned,
            $purchased,
        ));
    }

    public static function belowAssigned(int $target, int $assigned): self
    {
        return new self(sprintf(
            'Cannot release to %d seats: %d are assigned to members. Unassign a member first.',
            $target,
            $assigned,
        ));
    }

    public static function notEligible(string $subject): self
    {
        return new self(sprintf(
            'Subject [%s] is not an eligible member of this organization and cannot hold a seat.',
            $subject,
        ));
    }

    public static function belowOne(): self
    {
        return new self('A serving subscription must keep at least one purchased seat.');
    }

    public static function noSubscription(): self
    {
        return new self('This organization has no serving subscription to buy seats against.');
    }
}
