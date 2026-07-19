<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\ClassifiesDeclines;
use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Payments\Dunning\DeclineOutcome;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * The decline taxonomy. Classifies a failed charge into a canonical decline-code token and the
 * {@see DeclineCategory} the adaptive strategy recovers it by.
 *
 * TWO input shapes, because the engine loses the structured code at the gateway seam:
 *
 *  1. A CODE TOKEN — the scripted/manual gateway (and any adapter that propagates it) fails
 *     with the raw `decline_code` (`insufficient_funds`, `lost_card`, …). Matched exactly off
 *     {@see CODES}.
 *  2. A FREE-TEXT MESSAGE — the Stripe adapter fails with the SDK exception's `getMessage()`
 *     (`"Your card was declined."`), NOT the structured `decline_code` (it flattens the
 *     exception to a string; see docs/payments/adaptive-dunning.md → "Stripe boundary"). For
 *     that we normalize the sentence and phrase-match it back to the nearest canonical token
 *     via {@see PHRASES}, degrading to {@see DeclineCategory::Unknown} (retried conservatively)
 *     when nothing matches — never guessing "Hard" from an ambiguous message, so an unreadable
 *     decline is retried, not abandoned.
 *
 * Deterministic and side-effect free.
 */
readonly class DeclineClassifier implements ClassifiesDeclines
{
    /**
     * The canonical decline-code → category table. Keys are the network/Stripe `decline_code`
     * and card-error `code` tokens; the category drives recovery. Deny-by-default only for the
     * clearly-terminal codes — everything unlisted degrades to {@see DeclineCategory::Unknown}
     * and is retried, so a new issuer code never silently becomes a non-retryable Hard decline.
     *
     * @var array<string, DeclineCategory>
     */
    private const CODES = [
        // Hard — the method is dead; retrying it cannot succeed. A new method is required.
        'lost_card' => DeclineCategory::Hard,
        'stolen_card' => DeclineCategory::Hard,
        'pickup_card' => DeclineCategory::Hard,
        'fraudulent' => DeclineCategory::Hard,
        'merchant_blacklist' => DeclineCategory::Hard,
        'security_violation' => DeclineCategory::Hard,
        'stop_payment_order' => DeclineCategory::Hard,
        'revocation_of_authorization' => DeclineCategory::Hard,
        'revocation_of_all_authorizations' => DeclineCategory::Hard,
        'no_such_card' => DeclineCategory::Hard,
        'invalid_account' => DeclineCategory::Hard,
        'account_closed' => DeclineCategory::Hard,
        'closed_account' => DeclineCategory::Hard,
        'card_not_supported' => DeclineCategory::Hard,
        'currency_not_supported' => DeclineCategory::Hard,
        'restricted_card' => DeclineCategory::Hard,
        'not_permitted' => DeclineCategory::Hard,
        'pin_try_exceeded' => DeclineCategory::Hard,
        'expired_card' => DeclineCategory::Hard,
        'incorrect_number' => DeclineCategory::Hard,
        'invalid_number' => DeclineCategory::Hard,
        'invalid_expiry_year' => DeclineCategory::Hard,
        'invalid_expiry_month' => DeclineCategory::Hard,

        // Insufficient funds — recoverable but timing-sensitive (spread + payday-aware).
        'insufficient_funds' => DeclineCategory::InsufficientFunds,

        // Try-again-later — the issuer is telling us to back off; recoverable on a longer curve.
        'do_not_honor' => DeclineCategory::TryAgainLater,
        'do_not_honour' => DeclineCategory::TryAgainLater,
        'try_again_later' => DeclineCategory::TryAgainLater,
        'processing_error' => DeclineCategory::TryAgainLater,
        'issuer_not_available' => DeclineCategory::TryAgainLater,
        'reenter_transaction' => DeclineCategory::TryAgainLater,
        'approve_with_id' => DeclineCategory::TryAgainLater,
        'call_issuer' => DeclineCategory::TryAgainLater,
        'card_velocity_exceeded' => DeclineCategory::TryAgainLater,
        'service_not_allowed' => DeclineCategory::TryAgainLater,

        // Needs action — SCA / authentication; resolved by the customer, not a re-charge.
        'authentication_required' => DeclineCategory::NeedsAction,

        // Recoverable — generic / temporary soft declines. Retried on the ordinary curve.
        'generic_decline' => DeclineCategory::Recoverable,
        'card_declined' => DeclineCategory::Recoverable,
        'temporary_hold' => DeclineCategory::Recoverable,
        'authorization_error' => DeclineCategory::Recoverable,
        'test_mode_decline' => DeclineCategory::Recoverable,
        'testmode_decline' => DeclineCategory::Recoverable,
        'live_mode_test_card' => DeclineCategory::Recoverable,
    ];

    /**
     * Free-text phrase → canonical code, for the Stripe-adapter path where only the SDK
     * message survives. Scanned in order (most specific first) as case-normalized substrings.
     *
     * @var array<string, string>
     */
    private const PHRASES = [
        'insufficient funds' => 'insufficient_funds',
        'authentication required' => 'authentication_required',
        'authenticate' => 'authentication_required',
        '3d secure' => 'authentication_required',
        'lost card' => 'lost_card',
        'stolen card' => 'stolen_card',
        'card was reported lost' => 'lost_card',
        'card was reported stolen' => 'stolen_card',
        'pick up card' => 'pickup_card',
        'do not honor' => 'do_not_honor',
        'do not honour' => 'do_not_honor',
        'try again later' => 'try_again_later',
        'processing error' => 'processing_error',
        'call the issuer' => 'call_issuer',
        'call your bank' => 'call_issuer',
        'contact your bank' => 'call_issuer',
        'expired' => 'expired_card',
        'account is closed' => 'account_closed',
        'account has been closed' => 'account_closed',
        'no longer valid' => 'invalid_account',
        'not supported' => 'card_not_supported',
        'fraud' => 'fraudulent',
        'declined' => 'card_declined',
        'do_not_honor' => 'do_not_honor',
    ];

    public function classify(PaymentResult $result): DeclineOutcome
    {
        return $this->classifyReason($result->failureReason);
    }

    public function classifyReason(?string $reason): DeclineOutcome
    {
        $reason = $reason !== null ? trim($reason) : '';

        if ($reason === '') {
            return new DeclineOutcome('unknown', DeclineCategory::Unknown);
        }

        // 1) Exact code token (snake_case), possibly with surrounding punctuation stripped.
        $token = $this->tokenize($reason);

        if (isset(self::CODES[$token])) {
            return new DeclineOutcome($token, self::CODES[$token]);
        }

        // 2) Free-text phrase match (the Stripe SDK-message path).
        $haystack = strtolower($reason);

        foreach (self::PHRASES as $phrase => $code) {
            if (str_contains($haystack, $phrase)) {
                // Every PHRASES target is a key in CODES, so the category is always known.
                return new DeclineOutcome($code, self::CODES[$code]);
            }
        }

        // 3) A recognizable-looking token we simply don't have a rule for yet: keep the token
        // for analytics, retry conservatively.
        if ($token !== '' && preg_match('/^[a-z][a-z0-9_]{2,48}$/', $token) === 1) {
            return new DeclineOutcome($token, DeclineCategory::Unknown);
        }

        return new DeclineOutcome('unknown', DeclineCategory::Unknown);
    }

    /**
     * Reduce a reason to a bare snake_case token when it already is one (`"Insufficient_Funds"`
     * → `insufficient_funds`); a multi-word sentence collapses to something that won't match a
     * code key, falling through to the phrase pass.
     */
    private function tokenize(string $reason): string
    {
        $lower = strtolower(trim($reason));
        $lower = trim($lower, " \t\n\r\0\x0B.\"'");

        // A single code-like token has no spaces once hyphens are unified to underscores; a
        // multi-word sentence keeps its spaces and so never collides with a CODES key.
        return str_replace('-', '_', $lower);
    }
}
