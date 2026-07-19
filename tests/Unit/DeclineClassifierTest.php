<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Payments\DeclineClassifier;
use App\Billing\Payments\Dunning\DeclineCategory;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The decline taxonomy. A canonical code token classifies exactly; a free-text gateway message
 * (the Stripe SDK-message path) phrase-matches to the nearest code; an unrecognized reason
 * degrades to Unknown (retried conservatively) rather than guessing a non-retryable Hard.
 */
class DeclineClassifierTest extends TestCase
{
    private DeclineClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new DeclineClassifier;
    }

    /**
     * @return list<array{string, DeclineCategory, string}>
     */
    public static function codeVectors(): array
    {
        return [
            // Hard — retrying the same method cannot succeed.
            ['lost_card', DeclineCategory::Hard, 'lost_card'],
            ['stolen_card', DeclineCategory::Hard, 'stolen_card'],
            ['expired_card', DeclineCategory::Hard, 'expired_card'],
            ['fraudulent', DeclineCategory::Hard, 'fraudulent'],
            ['account_closed', DeclineCategory::Hard, 'account_closed'],
            // Insufficient funds — its own timing-sensitive category.
            ['insufficient_funds', DeclineCategory::InsufficientFunds, 'insufficient_funds'],
            // Try-again-later — issuer backoff.
            ['do_not_honor', DeclineCategory::TryAgainLater, 'do_not_honor'],
            ['try_again_later', DeclineCategory::TryAgainLater, 'try_again_later'],
            ['processing_error', DeclineCategory::TryAgainLater, 'processing_error'],
            // Needs action — SCA.
            ['authentication_required', DeclineCategory::NeedsAction, 'authentication_required'],
            // Recoverable — generic soft declines.
            ['card_declined', DeclineCategory::Recoverable, 'card_declined'],
            ['generic_decline', DeclineCategory::Recoverable, 'generic_decline'],
        ];
    }

    #[DataProvider('codeVectors')]
    public function test_it_classifies_canonical_decline_codes(string $code, DeclineCategory $expected, string $expectedCode): void
    {
        $outcome = $this->classifier->classifyReason($code);

        $this->assertSame($expected, $outcome->category);
        $this->assertSame($expectedCode, $outcome->code);
    }

    /**
     * @return list<array{string, DeclineCategory, string}>
     */
    public static function messageVectors(): array
    {
        return [
            ['Your card has insufficient funds.', DeclineCategory::InsufficientFunds, 'insufficient_funds'],
            ['Your card was declined.', DeclineCategory::Recoverable, 'card_declined'],
            ['The card was reported lost.', DeclineCategory::Hard, 'lost_card'],
            ['Your card has expired.', DeclineCategory::Hard, 'expired_card'],
            ['The issuer asked us to try again later.', DeclineCategory::TryAgainLater, 'try_again_later'],
            ['Authentication required to complete this payment.', DeclineCategory::NeedsAction, 'authentication_required'],
            ['The bank returned do not honor.', DeclineCategory::TryAgainLater, 'do_not_honor'],
        ];
    }

    #[DataProvider('messageVectors')]
    public function test_it_classifies_free_text_gateway_messages(string $message, DeclineCategory $expected, string $expectedCode): void
    {
        $outcome = $this->classifier->classifyReason($message);

        $this->assertSame($expected, $outcome->category);
        $this->assertSame($expectedCode, $outcome->code);
    }

    public function test_an_empty_reason_is_unknown(): void
    {
        $outcome = $this->classifier->classifyReason(null);

        $this->assertSame(DeclineCategory::Unknown, $outcome->category);
        $this->assertSame('unknown', $outcome->code);
    }

    public function test_an_unrecognized_code_token_is_kept_but_classified_unknown(): void
    {
        // A never-seen issuer code is retained for analytics and retried conservatively — never
        // guessed into Hard.
        $outcome = $this->classifier->classifyReason('brand_new_issuer_code');

        $this->assertSame(DeclineCategory::Unknown, $outcome->category);
        $this->assertSame('brand_new_issuer_code', $outcome->code);
    }

    public function test_it_classifies_from_a_payment_result(): void
    {
        $outcome = $this->classifier->classify(PaymentResult::failed('lost_card'));

        $this->assertSame(DeclineCategory::Hard, $outcome->category);
    }
}
