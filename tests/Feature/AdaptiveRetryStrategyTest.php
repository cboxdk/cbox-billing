<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Payments\Contracts\SchedulesRetries;
use App\Billing\Payments\Dunning\DeclineCategory;
use App\Models\DunningStrategy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The adaptive schedule proves the timing heuristics actually adapt per decline category — the
 * crux of the whole feature. Every instant is asserted against a fixed first-failure date, so
 * the cadence is deterministic (no test clock needed — the strategy is pure over the instant).
 */
class AdaptiveRetryStrategyTest extends TestCase
{
    use RefreshDatabase;

    private function strategy(): SchedulesRetries
    {
        return app(SchedulesRetries::class);
    }

    public function test_the_base_recoverable_curve_is_the_legacy_fixed_schedule(): void
    {
        $strategy = $this->strategy();
        $ff = CarbonImmutable::parse('2026-06-10'); // a Wednesday

        // Recoverable inherits the base [1,3,5,7] with NO heuristics — identical to the old flow.
        $this->assertSame('2026-06-11', $strategy->attemptAt(DeclineCategory::Recoverable, 1, $ff)?->toDateString());
        $this->assertSame('2026-06-13', $strategy->attemptAt(DeclineCategory::Recoverable, 2, $ff)?->toDateString());
        $this->assertSame('2026-06-15', $strategy->attemptAt(DeclineCategory::Recoverable, 3, $ff)?->toDateString());
        $this->assertSame('2026-06-17', $strategy->attemptAt(DeclineCategory::Recoverable, 4, $ff)?->toDateString());
        // Past the ceiling → exhausted.
        $this->assertNull($strategy->attemptAt(DeclineCategory::Recoverable, 5, $ff));
        $this->assertSame(4, $strategy->planFor(DeclineCategory::Recoverable)->maxAttempts);
    }

    public function test_insufficient_funds_spreads_wider_and_aligns_to_payday(): void
    {
        $strategy = $this->strategy();
        $ff = CarbonImmutable::parse('2026-06-10'); // Wednesday

        $base = $strategy->attemptAt(DeclineCategory::Recoverable, 1, $ff);
        $adaptive = $strategy->attemptAt(DeclineCategory::InsufficientFunds, 1, $ff);

        $this->assertNotNull($adaptive);
        $this->assertNotNull($base);

        // It ADAPTS: the first insufficient-funds attempt is not the base day-1 instant.
        $this->assertNotSame($base->toDateString(), $adaptive->toDateString());

        // Raw offset 2 → 2026-06-12 (Fri); pulled forward to the next payday anchor (the 15th),
        // which is a Monday — a weekday, so it stays.
        $this->assertSame('2026-06-15', $adaptive->toDateString());
        $this->assertSame(15, $adaptive->day);
        $this->assertNotContains($adaptive->isoWeekday(), [6, 7]);

        // The curve is genuinely wider than the base curve.
        $this->assertSame([2, 5, 9, 14], $strategy->planFor(DeclineCategory::InsufficientFunds)->backoffDays);
    }

    public function test_weekend_attempts_are_pushed_onto_the_next_weekday(): void
    {
        $strategy = $this->strategy();
        $ff = CarbonImmutable::parse('2026-03-05'); // Thursday

        // try_again_later: raw offset 2 → 2026-03-07 (Saturday); avoid-weekends pushes to Monday.
        $at = $strategy->attemptAt(DeclineCategory::TryAgainLater, 1, $ff);

        $this->assertNotNull($at);
        $this->assertSame('2026-03-09', $at->toDateString());
        $this->assertSame(1, $at->isoWeekday()); // Monday
        $this->assertNotContains($at->isoWeekday(), [6, 7]);
    }

    public function test_try_again_later_uses_a_longer_backoff(): void
    {
        $plan = $this->strategy()->planFor(DeclineCategory::TryAgainLater);

        $this->assertSame([2, 5, 10, 16, 24], $plan->backoffDays);
        $this->assertSame(5, $plan->maxAttempts); // longer than the base 4
    }

    public function test_a_hard_category_is_never_retried(): void
    {
        $strategy = $this->strategy();
        $ff = CarbonImmutable::parse('2026-06-10');

        $this->assertFalse($strategy->planFor(DeclineCategory::Hard)->retry);
        $this->assertNull($strategy->attemptAt(DeclineCategory::Hard, 1, $ff));
    }

    public function test_needs_action_uses_a_short_curve(): void
    {
        $plan = $this->strategy()->planFor(DeclineCategory::NeedsAction);

        $this->assertSame([1, 3, 5], $plan->backoffDays);
        $this->assertSame(3, $plan->maxAttempts);
    }

    public function test_an_attempt_beyond_the_recovery_window_is_dropped(): void
    {
        // A tuned override whose single offset falls outside the 30-day window → no attempt.
        DunningStrategy::query()->create([
            'category' => DeclineCategory::Recoverable->value,
            'retry' => true,
            'backoff_days' => [40],
            'max_attempts' => null,
            'avoid_weekends' => false,
            'align_to_payday' => false,
        ]);

        $strategy = $this->strategy();
        $ff = CarbonImmutable::parse('2026-06-10');

        // The override is honored...
        $this->assertSame([40], $strategy->planFor(DeclineCategory::Recoverable)->backoffDays);
        // ...but day 40 > the 30-day window, so the schedule exhausts rather than chasing it.
        $this->assertNull($strategy->attemptAt(DeclineCategory::Recoverable, 1, $ff));
    }

    public function test_a_db_override_wins_over_the_config_default(): void
    {
        DunningStrategy::query()->create([
            'category' => DeclineCategory::Recoverable->value,
            'retry' => true,
            'backoff_days' => [4, 8],
            'max_attempts' => null,
            'avoid_weekends' => false,
            'align_to_payday' => false,
        ]);

        $plan = $this->strategy()->planFor(DeclineCategory::Recoverable);

        $this->assertSame([4, 8], $plan->backoffDays);
        $this->assertSame(2, $plan->maxAttempts);
    }
}
