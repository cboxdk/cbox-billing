<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Exceptions;

use App\Billing\Approvals\Enums\ApprovalActionType;
use RuntimeException;

/**
 * A server-side refusal in the approval workflow. Every guard throws one of these rather than
 * trusting the UI — the two-person rule, the pending-state guard, one-decision-per-checker,
 * and the deny-by-default registry lookup. Controllers catch it and flash the message back.
 */
class ApprovalDenied extends RuntimeException
{
    /** The maker cannot also be the checker — a second, distinct operator must decide. */
    public static function selfApproval(): self
    {
        return new self('You cannot decide your own request — a different operator must approve it.');
    }

    /** A decision was attempted on a request that is not awaiting one. */
    public static function notPending(): self
    {
        return new self('This request is no longer pending a decision.');
    }

    /** This checker has already recorded a decision on the request. */
    public static function alreadyDecided(): self
    {
        return new self('You have already recorded a decision on this request.');
    }

    /** The maker tried to cancel a request that is not theirs, or not pending. */
    public static function notCancelable(): self
    {
        return new self('This request can no longer be canceled.');
    }

    /** The registry has no factory for the request's action type — fail closed. */
    public static function unknownAction(string $type): self
    {
        return new self(sprintf('No approvable action is registered for type [%s].', $type));
    }

    /** A duplicate factory registration for the same type. */
    public static function duplicateAction(ApprovalActionType $type): self
    {
        return new self(sprintf('An approvable action is already registered for type [%s].', $type->value));
    }
}
