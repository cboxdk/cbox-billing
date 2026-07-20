<?php

declare(strict_types=1);

namespace App\Billing\Approvals\ValueObjects;

use App\Auth\AuthedUser;

/**
 * The operator identity attached to a maker (who requested) or a checker (who decided) on an
 * approval. `sub` is the canonical Cbox ID subject — the value the two-person rule compares,
 * so a maker can never also be a checker. `name` is display-only.
 */
readonly class ApprovalActor
{
    public function __construct(
        public string $sub,
        public ?string $name = null,
    ) {}

    /** The actor for a signed-in operator, or null when the session carries no identity. */
    public static function fromUser(?AuthedUser $user): ?self
    {
        if ($user === null || $user->sub === '') {
            return null;
        }

        return new self($user->sub, $user->name !== '' ? $user->name : null);
    }
}
