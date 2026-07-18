<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Cbox\Billing\Retention\Events\RetentionResolved;
use Cbox\Billing\Retention\Events\SubscriptionCancellationRequested;
use Cbox\Billing\Retention\NullCancellationSurvey;
use Cbox\Billing\Retention\NullRetentionOffers;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The retention seam (ADR-0016 wiring): the app binds a basic {@see CancellationSurvey} +
 * {@see RetentionOffers} over the engine's inert Null defaults, the cancel UI (console +
 * portal) renders whatever the bound seam returns, and the cancel path emits the engine's
 * {@see RetentionRecorder} domain events so a plugin can enrich the flow with zero app edits.
 */
class RetentionSeamTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_the_app_binds_a_basic_survey_and_offers_over_the_engine_nulls(): void
    {
        // The app's basic defaults win over the engine's Null defaults.
        $this->assertNotInstanceOf(NullCancellationSurvey::class, app(CancellationSurvey::class));
        $this->assertNotInstanceOf(NullRetentionOffers::class, app(RetentionOffers::class));

        $reasons = app(CancellationSurvey::class)->reasonsFor('org', 'sub');
        $offers = app(RetentionOffers::class)->offersFor('org', 'sub');

        $this->assertNotEmpty($reasons);
        $this->assertNotEmpty($offers);
    }

    public function test_the_console_cancel_ui_renders_the_bound_survey_reasons_and_offers(): void
    {
        $subscription = $this->subscribed('org_console');

        $this->withSession($this->session)->get('/subscriptions/'.$subscription->id)
            ->assertOk()
            // Survey reasons (basic default).
            ->assertSee('Too expensive')
            ->assertSee('Switching provider')
            // Save-offers (basic default: pause instead of cancel).
            ->assertSee('Save offers')
            ->assertSee('Pause instead');
    }

    public function test_the_portal_cancel_ui_renders_the_bound_survey_reasons_and_offers(): void
    {
        $this->subscribed('org_portal');
        $session = $this->portalSession('org_portal');

        $this->get('/billing/portal/'.$session->token)
            ->assertOk()
            ->assertSee('Too expensive')
            ->assertSee('Before you go')
            ->assertSee('Pause instead');
    }

    public function test_the_cancel_path_records_the_retention_events(): void
    {
        Event::fake([SubscriptionCancellationRequested::class, RetentionResolved::class]);

        $subscription = $this->subscribed('org_events');

        $this->withSession($this->session)
            ->post('/subscriptions/'.$subscription->id.'/cancel', [
                'mode' => 'period_end',
                'reason' => 'too_expensive',
                'feedback' => 'Wrong time.',
            ])
            ->assertRedirect('/subscriptions/'.$subscription->id);

        // The seam emitted the requested + resolved events, carrying the captured reason.
        Event::assertDispatched(SubscriptionCancellationRequested::class, function (SubscriptionCancellationRequested $event): bool {
            return $event->account === 'org_events' && $event->response?->reasonKey === 'too_expensive';
        });
        Event::assertDispatched(RetentionResolved::class, function (RetentionResolved $event): bool {
            return $event->response?->reasonKey === 'too_expensive';
        });

        // The existing reason-capture log still records the cancellation.
        $this->assertDatabaseHas('subscription_cancellations', [
            'organization_id' => 'org_events',
            'mode' => 'period_end',
            'reason' => 'too_expensive',
        ]);
    }

    private function subscribed(string $org): Subscription
    {
        $organization = Organization::query()->create([
            'id' => $org,
            'name' => ucfirst($org),
            'billing_email' => $org.'@example.test',
            'billing_country' => 'DK',
        ]);

        return app(SubscribesOrganizations::class)->subscribe($organization, Plan::query()->where('key', 'starter')->firstOrFail());
    }

    private function portalSession(string $org): BillingSession
    {
        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        $this->postJson('/api/v1/portal-sessions', [
            'org' => $org,
            'return_url' => 'https://merchant.example/account',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        return BillingSession::query()->where('organization_id', $org)->where('type', 'portal')->firstOrFail();
    }
}
