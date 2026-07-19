<?php

declare(strict_types=1);

namespace App\Billing\Payments\Dunning;

use App\Models\PaymentRetry;

/**
 * The recovery taxonomy a failed charge's decline code is classified into — the single axis
 * the adaptive retry strategy branches on. Where a static schedule treats every decline the
 * same, this splits them by *why* the charge failed so the recovery behaviour can differ:
 *
 *  - {@see Hard}              — lost/stolen/closed/fraud/expired/invalid. Retrying the SAME
 *                              method can never succeed, so the schedule is NOT opened: the
 *                              flow short-circuits to the terminal action + a "update your
 *                              payment method" notice (and, where wired, a retention offer). A
 *                              network card-updater push later can re-open recovery.
 *  - {@see InsufficientFunds} — the classic recoverable decline, but timing-sensitive: an
 *                              immediate re-try just declines again, so it is spread wider and
 *                              nudged toward likely payday windows.
 *  - {@see Recoverable}       — a generic / temporary / issuer soft decline. Retry on the
 *                              ordinary adaptive curve.
 *  - {@see TryAgainLater}     — do-not-honor / issuer-unavailable / rate-limited. Recoverable
 *                              but the issuer is telling us to back off, so a LONGER backoff.
 *  - {@see NeedsAction}       — SCA / authentication required. Retrying off-session declines;
 *                              the customer must authenticate, so an authenticate link is sent.
 *  - {@see Unknown}           — an unrecognized decline. Retried conservatively (as
 *                              {@see Recoverable}) but tagged so recovery analytics can surface
 *                              how much of the book is landing in codes the taxonomy misses.
 *
 * The enum value is the stable key stored on the {@see PaymentRetry} row and the
 * key a per-category strategy override is filed under (config + the DB overrides).
 */
enum DeclineCategory: string
{
    case Hard = 'hard';

    case InsufficientFunds = 'insufficient_funds';

    case Recoverable = 'recoverable';

    case TryAgainLater = 'try_again_later';

    case NeedsAction = 'needs_action';

    case Unknown = 'unknown';

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    /** A short operator-facing label for the console. */
    public function label(): string
    {
        return match ($this) {
            self::Hard => 'Hard decline',
            self::InsufficientFunds => 'Insufficient funds',
            self::Recoverable => 'Recoverable',
            self::TryAgainLater => 'Try again later',
            self::NeedsAction => 'Needs authentication',
            self::Unknown => 'Unknown',
        };
    }

    /** A one-line explanation of how this category is recovered — shown next to the schedule. */
    public function description(): string
    {
        return match ($this) {
            self::Hard => 'Retrying the same method cannot succeed — no automatic retries; the customer is asked to update their payment method.',
            self::InsufficientFunds => 'The balance was short — attempts are spread wider and nudged toward likely payday windows, avoiding weekends.',
            self::Recoverable => 'A temporary or issuer decline — retried on the adaptive backoff curve.',
            self::TryAgainLater => 'The issuer asked us to back off — retried on a longer backoff.',
            self::NeedsAction => 'The bank requires authentication — the customer is sent a link to authenticate the payment.',
            self::Unknown => 'An unrecognized decline — retried conservatively on the default curve.',
        };
    }

    /** The design-system pill variant the console renders this category with. */
    public function pill(): string
    {
        return match ($this) {
            self::Hard => 'destructive',
            self::InsufficientFunds, self::TryAgainLater => 'warning',
            self::NeedsAction => 'info',
            self::Recoverable => 'success',
            self::Unknown => 'muted',
        };
    }

    /**
     * Whether a decline in this category is worth retrying at all. A {@see Hard} decline is
     * terminal (a new method is required); every other category is recoverable and opens the
     * adaptive schedule. The per-category strategy can still narrow this via its `retry` knob,
     * but a Hard decline is never retried regardless of configuration.
     */
    public function isRecoverable(): bool
    {
        return $this !== self::Hard;
    }

    /** Whether this category is resolved by the customer authenticating (SCA), not by a re-charge. */
    public function needsCustomerAction(): bool
    {
        return $this === self::NeedsAction;
    }
}
