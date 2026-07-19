<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Reporting\RecoveryAnalytics;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentRetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Recovery analytics compute the exact rate, category breakdown, average attempts-to-recover
 * and recovered revenue over a seeded set of retry rows.
 */
class RecoveryAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::query()->create([
            'id' => 'org_ra', 'name' => 'Recovery Co', 'billing_country' => 'DK', 'billing_email' => 'billing@org_ra.test',
        ]);

        // A deterministic book of six dunning entries:
        //   2 insufficient-funds recovered (2 + 4 attempts, 1000 + 2000 minor)
        //   1 recoverable   recovered (1 attempt, 500 minor)
        //   1 recoverable   still retrying (1 attempt)
        //   1 hard          exhausted
        //   1 try-again     exhausted
        $this->seedRetry(DeclineCategory::InsufficientFunds, PaymentRetry::STATUS_RECOVERED, 2, 1000);
        $this->seedRetry(DeclineCategory::InsufficientFunds, PaymentRetry::STATUS_RECOVERED, 4, 2000);
        $this->seedRetry(DeclineCategory::Recoverable, PaymentRetry::STATUS_RECOVERED, 1, 500);
        $this->seedRetry(DeclineCategory::Recoverable, PaymentRetry::STATUS_RETRYING, 1, 900);
        $this->seedRetry(DeclineCategory::Hard, PaymentRetry::STATUS_EXHAUSTED, 0, 700);
        $this->seedRetry(DeclineCategory::TryAgainLater, PaymentRetry::STATUS_EXHAUSTED, 3, 700);
    }

    public function test_it_computes_the_headline_recovery_figures(): void
    {
        $analytics = app(RecoveryAnalytics::class);

        $this->assertSame(6, $analytics->entered());
        $this->assertSame(3, $analytics->recovered());
        $this->assertSame(2, $analytics->exhausted());
        $this->assertSame(1, $analytics->active());

        // 3 of 6 recovered.
        $this->assertSame(0.5, $analytics->recoveryRate());

        // Attempts on the recovered rows: (2 + 4 + 1) / 3 = 2.33.
        $this->assertSame(2.33, $analytics->averageAttemptsToRecover());

        // Recovered subscriptions saved from involuntary churn = the 3 recovered.
        $this->assertSame(3, $analytics->involuntaryChurnAverted());
        $this->assertSame(2, $analytics->involuntaryChurn());
    }

    public function test_it_computes_recovered_revenue_per_currency(): void
    {
        $revenue = app(RecoveryAnalytics::class)->recoveredRevenue();

        // 1000 + 2000 + 500 = 3500 minor DKK (only the recovered rows count).
        $this->assertArrayHasKey('DKK', $revenue);
        $this->assertSame(3500, $revenue['DKK']->minor());
        $this->assertSame('DKK', $revenue['DKK']->currency());
    }

    public function test_it_breaks_recovery_down_by_decline_category(): void
    {
        $byCategory = collect(app(RecoveryAnalytics::class)->byCategory())->keyBy('category');

        $this->assertSame(2, $byCategory['insufficient_funds']['entered']);
        $this->assertSame(2, $byCategory['insufficient_funds']['recovered']);
        $this->assertSame(1.0, $byCategory['insufficient_funds']['rate']);

        $this->assertSame(2, $byCategory['recoverable']['entered']);
        $this->assertSame(1, $byCategory['recoverable']['recovered']);
        $this->assertSame(0.5, $byCategory['recoverable']['rate']);

        $this->assertSame(1, $byCategory['hard']['entered']);
        $this->assertSame(0, $byCategory['hard']['recovered']);
        $this->assertSame(0.0, $byCategory['hard']['rate']);

        $this->assertSame(1, $byCategory['try_again_later']['entered']);
    }

    private function seedRetry(DeclineCategory $category, string $status, int $attempts, int $totalMinor): void
    {
        $this->counter++;

        $invoice = Invoice::query()->create([
            'organization_id' => 'org_ra',
            'seller' => 'cbox-dk',
            'number' => 'RA-'.$this->counter,
            'currency' => 'DKK',
            'subtotal_minor' => $totalMinor,
            'total_minor' => $totalMinor,
            'status' => $status === PaymentRetry::STATUS_RECOVERED ? 'paid' : 'open',
        ]);

        PaymentRetry::query()->create([
            'invoice_id' => $invoice->id,
            'organization_id' => 'org_ra',
            'subscription_id' => null,
            'attempts' => $attempts,
            'max_attempts' => max(1, $attempts),
            'status' => $status,
            'decline_category' => $category->value,
            'decline_code' => $category->value,
            'first_failed_at' => Carbon::now()->subDays(5),
        ]);
    }
}
