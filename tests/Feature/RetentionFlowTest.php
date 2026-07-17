<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The retention flows (Part 2), through the management API: a cancellation captures its
 * reason and offers the fork (immediate vs cancel-at-period-end vs pause-instead-of-cancel),
 * and win-back reactivation resumes a paused subscription or re-activates a recently-canceled
 * one. Reasons are persisted to the append-only cancellation log for analytics.
 */
class RetentionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_cancel_captures_a_reason_and_offers_period_end_then_immediate(): void
    {
        $auth = $this->subscribed('org_cancel');

        // --- Cancel at period end: stays active (serving), no longer renews, reason logged ---
        $this->postJson('/api/v1/subscriptions/org_cancel/cancel', [
            'mode' => 'period_end',
            'reason' => 'too_expensive',
            'feedback' => 'Great product, wrong time.',
        ], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('renews_at', null);

        $this->assertDatabaseHas('subscription_cancellations', [
            'organization_id' => 'org_cancel',
            'mode' => SubscriptionCancellation::MODE_PERIOD_END,
            'reason' => 'too_expensive',
            'feedback' => 'Great product, wrong time.',
        ]);

        // --- Cancel immediately: engine forfeiture-on-transition, canceled_at stamped ---
        $this->postJson('/api/v1/subscriptions/org_cancel/cancel', [
            'mode' => 'immediate',
            'reason' => 'switching_provider',
        ], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'canceled');

        $this->assertDatabaseHas('subscription_cancellations', [
            'organization_id' => 'org_cancel',
            'mode' => SubscriptionCancellation::MODE_IMMEDIATE,
            'reason' => 'switching_provider',
        ]);
        $this->assertNotNull(Subscription::query()->where('organization_id', 'org_cancel')->first()?->canceled_at);
    }

    public function test_legacy_at_period_end_flag_still_schedules_a_cancellation(): void
    {
        $auth = $this->subscribed('org_legacy');

        // No `mode` supplied: the legacy boolean drives it (default → period end).
        $this->postJson('/api/v1/subscriptions/org_legacy/cancel', [
            'at_period_end' => true,
        ], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('renews_at', null);

        $this->assertDatabaseHas('subscription_cancellations', [
            'organization_id' => 'org_legacy',
            'mode' => SubscriptionCancellation::MODE_PERIOD_END,
        ]);
    }

    public function test_pause_instead_of_cancel_saves_the_subscription_then_reactivates(): void
    {
        $auth = $this->subscribed('org_pause');

        // --- Pause-instead-of-cancel: access suspended, reason still captured ---
        $this->postJson('/api/v1/subscriptions/org_pause/cancel', [
            'mode' => 'pause',
            'reason' => 'taking_a_break',
        ], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('paused', true);

        $this->assertDatabaseHas('subscription_cancellations', [
            'organization_id' => 'org_pause',
            'mode' => SubscriptionCancellation::MODE_PAUSE,
            'reason' => 'taking_a_break',
        ]);

        // --- Win back: reactivate lifts the pause ---
        $this->postJson('/api/v1/subscriptions/org_pause/reactivate', [], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('paused', false);

        $this->assertDatabaseHas('subscription_cancellations', [
            'organization_id' => 'org_pause',
            'mode' => SubscriptionCancellation::MODE_REACTIVATE,
        ]);
    }

    public function test_win_back_reactivates_a_recently_canceled_subscription(): void
    {
        $auth = $this->subscribed('org_winback');

        $this->postJson('/api/v1/subscriptions/org_winback/cancel', [
            'mode' => 'immediate',
            'reason' => 'mistake',
        ], $auth)->assertOk()->assertJsonPath('status', 'canceled');

        // Reactivate re-subscribes to the same plan (within the win-back window).
        $this->postJson('/api/v1/subscriptions/org_winback/reactivate', [], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('plan', 'starter');

        $this->assertDatabaseHas('subscription_cancellations', [
            'organization_id' => 'org_winback',
            'mode' => SubscriptionCancellation::MODE_REACTIVATE,
        ]);
    }

    public function test_reactivate_conflicts_when_the_subscription_is_not_reactivatable(): void
    {
        $auth = $this->subscribed('org_active');

        // A healthy active subscription is not in any reactivatable state → 409.
        $this->postJson('/api/v1/subscriptions/org_active/reactivate', [], $auth)
            ->assertStatus(409);
    }

    /** Create an org with a token and an active starter subscription; return the auth header. */
    private function subscribed(string $id): array
    {
        Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);

        ['plaintext' => $token] = ApiToken::issue($id.'-sdk', $id);
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/v1/subscriptions', ['org' => $id, 'plan' => 'starter'], $auth)->assertCreated();

        return $auth;
    }
}
