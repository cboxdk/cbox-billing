<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Notifications\Contracts\ManagesNotificationPreferences;
use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Notifications\MailEventType;
use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Mail\PaymentFailedMail;
use App\Mail\RenewalReminderMail;
use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\CboxIdAccessGrant;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\SeatAssignment;
use App\Models\Subscription;
use Cbox\Billing\Money\Money;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The deepened customer self-service portal (all token-scoped, all confined to the session's
 * organization): the usage/consumption view, self-serve seats (buy/release + assign), the
 * broad billing-history timeline, and the optional-notification preferences — plus the
 * cross-org isolation invariant on every new endpoint.
 */
class PortalSelfServeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    private function subscribe(string $org, string $plan = 'starter', int $seats = 1): Subscription
    {
        Organization::query()->create([
            'id' => $org,
            'name' => ucfirst($org),
            'billing_email' => $org.'@example.test',
            'billing_country' => 'DK',
        ]);

        app(SubscribesOrganizations::class)->subscribe(
            Organization::query()->findOrFail($org),
            Plan::query()->where('key', $plan)->firstOrFail(),
            seats: $seats,
        );

        return Subscription::query()->where('organization_id', $org)->serving()->firstOrFail();
    }

    private function portalToken(string $org): string
    {
        ['plaintext' => $token] = ApiToken::issue($org.'-sdk', $org);

        $this->postJson('/api/v1/portal-sessions', [
            'org' => $org,
            'return_url' => 'https://merchant.example/account',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        return BillingSession::query()->where('organization_id', $org)->where('type', 'portal')->firstOrFail()->token;
    }

    /** A plain per-seat plan so a seat change genuinely prorates a positive charge. */
    private function perSeatPlan(): string
    {
        $product = Product::query()->firstOrFail();
        $plan = Plan::query()->create([
            'product_id' => $product->id,
            'key' => 'perseat',
            'name' => 'Per Seat',
            'interval' => 'month',
            'active' => true,
        ]);
        PlanPrice::query()->create([
            'plan_id' => $plan->id,
            'currency' => 'DKK',
            'price_minor' => 10_000,
            'pricing_model' => 'per_unit',
        ]);

        return 'perseat';
    }

    /** A flat plan with NO metered entitlements, so the usage section must hide entirely. */
    private function flatPlan(): string
    {
        $product = Product::query()->firstOrFail();
        $plan = Plan::query()->create([
            'product_id' => $product->id,
            'key' => 'flat',
            'name' => 'Flat',
            'interval' => 'month',
            'active' => true,
        ]);
        PlanPrice::query()->create([
            'plan_id' => $plan->id,
            'currency' => 'DKK',
            'price_minor' => 9_900,
            'pricing_model' => 'flat',
        ]);

        return 'flat';
    }

    private function mirror(string $org, string $subject, string $role = 'billing-operator'): void
    {
        CboxIdAccessGrant::query()->create(['organization_id' => $org, 'subject' => $subject, 'role' => $role]);
    }

    // --- 1. Usage & consumption ------------------------------------------------------------

    public function test_usage_section_renders_the_orgs_allowance_for_a_metered_plan(): void
    {
        $this->subscribe('org_usage', 'starter');
        $token = $this->portalToken('org_usage');

        // The same numbers the enforcement path reads (EntitlementsView + UsageSummaryView):
        // Starter includes 100 000 API requests and 3 seats, with 0 used this period.
        $this->get('/billing/portal/'.$token)
            ->assertOk()
            ->assertSee('Usage this period')
            ->assertSee('API requests')
            ->assertSee('100,000')
            ->assertSee('remaining');
    }

    public function test_usage_section_is_hidden_for_a_flat_plan(): void
    {
        $this->subscribe('org_flat', $this->flatPlan());

        $this->get('/billing/portal/'.$this->portalToken('org_flat'))
            ->assertOk()
            ->assertDontSee('Usage this period');
    }

    // --- 2. Self-serve seats ---------------------------------------------------------------

    public function test_seat_buy_previews_then_raises_the_billed_quantity_and_collects_the_proration(): void
    {
        $this->subscribe('org_seatbuy', $this->perSeatPlan(), seats: 2);
        $token = $this->portalToken('org_seatbuy');

        // Preview is non-destructive and reports a positive, prorated due-now (< the full delta).
        $preview = $this->postJson('/billing/portal/'.$token.'/seats/preview', ['seats' => 4]);
        $preview->assertOk()->assertJsonPath('to_seats', 4)->assertJsonPath('is_credit', false);
        $dueNow = (int) $preview->json('due_now_minor');
        $this->assertGreaterThan(0, $dueNow);
        $this->assertLessThan(20_000, $dueNow);
        $this->assertSame(2, Subscription::query()->where('organization_id', 'org_seatbuy')->serving()->firstOrFail()->seats);

        // Confirm buys the seats through the engine's changeQuantity: billed quantity rises and
        // the prorated charge is COLLECTED — the H6 collector issues a real prorated invoice.
        $this->postJson('/billing/portal/'.$token.'/seats', ['seats' => 4])
            ->assertOk()
            ->assertJsonPath('purchased', 4);

        $this->assertSame(4, Subscription::query()->where('organization_id', 'org_seatbuy')->serving()->firstOrFail()->seats);
        $this->assertTrue(
            Invoice::query()->where('organization_id', 'org_seatbuy')->where('total_minor', '>', 0)->exists(),
            'Buying seats must collect the prorated charge via a real invoice.',
        );
    }

    public function test_seat_assign_and_unassign_move_a_member_between_full_and_light(): void
    {
        $this->subscribe('org_assign', 'team', seats: 2);
        $token = $this->portalToken('org_assign');
        $this->mirror('org_assign', 'user_a');

        $this->postJson('/billing/portal/'.$token.'/seats/assign', ['subject' => 'user_a'])
            ->assertOk()
            ->assertJsonPath('full_count', 1)
            ->assertJsonPath('light_count', 0);
        $this->assertTrue(SeatAssignment::query()->where('organization_id', 'org_assign')->where('subject', 'user_a')->exists());

        $this->postJson('/billing/portal/'.$token.'/seats/unassign', ['subject' => 'user_a'])
            ->assertOk()
            ->assertJsonPath('full_count', 0)
            ->assertJsonPath('light_count', 1);
        $this->assertFalse(SeatAssignment::query()->where('organization_id', 'org_assign')->where('subject', 'user_a')->exists());
    }

    public function test_assigning_beyond_the_purchased_cap_is_refused_with_buy_more_seats(): void
    {
        $this->subscribe('org_cap', 'team', seats: 1);
        $token = $this->portalToken('org_cap');
        $this->mirror('org_cap', 'first');
        $this->mirror('org_cap', 'second');

        $this->postJson('/billing/portal/'.$token.'/seats/assign', ['subject' => 'first'])->assertOk();

        // No free seat for the second — refused (422) with the guardrail message.
        $this->postJson('/billing/portal/'.$token.'/seats/assign', ['subject' => 'second'])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'No free seat to assign: 1 of 1 purchased seats are already assigned. Buy more seats first.']);

        $this->assertSame(1, SeatAssignment::query()->where('organization_id', 'org_cap')->count());
    }

    public function test_releasing_seats_below_the_assigned_count_is_refused(): void
    {
        $subscription = $this->subscribe('org_release', 'team', seats: 3);
        $token = $this->portalToken('org_release');

        foreach (['m1', 'm2', 'm3'] as $subject) {
            $this->mirror('org_release', $subject);
            app(ManagesSeats::class)->assign($subscription, $subject);
        }

        $this->postJson('/billing/portal/'.$token.'/seats', ['seats' => 2])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Cannot release to 2 seats: 3 are assigned to members. Unassign a member first.']);

        $this->assertSame(3, $subscription->refresh()->seats);
    }

    // --- Cross-org isolation ---------------------------------------------------------------

    public function test_a_portal_token_is_confined_to_its_own_org(): void
    {
        $this->subscribe('org_a', 'team', seats: 2);
        $this->subscribe('org_b', 'team', seats: 2);
        $tokenA = $this->portalToken('org_a');

        // org_b's member is not eligible under org_a's token → refused; org_b untouched.
        $this->mirror('org_b', 'b_member');
        $this->postJson('/billing/portal/'.$tokenA.'/seats/assign', ['subject' => 'b_member'])
            ->assertStatus(422);
        $this->assertSame(0, SeatAssignment::query()->where('organization_id', 'org_b')->count());

        // org_b's invoice is not downloadable through org_a's token (404, never leaks it exists).
        $invoiceB = Invoice::query()->create([
            'organization_id' => 'org_b', 'seller' => 'seller_x', 'number' => 'CBOX-B-000001',
            'currency' => 'DKK', 'subtotal_minor' => 10_000, 'tax_minor' => 2_500, 'total_minor' => 12_500,
            'status' => 'open', 'issued_at' => Carbon::now(),
        ]);
        $this->get('/billing/portal/'.$tokenA.'/invoices/'.$invoiceB->id.'/pdf')->assertNotFound();
    }

    // --- 3. Billing history ----------------------------------------------------------------

    public function test_billing_history_lists_invoice_payment_and_credit_note_in_order(): void
    {
        $this->subscribe('org_hist', 'team', seats: 1);
        $token = $this->portalToken('org_hist');

        // An invoice issued 3 days ago and paid 2 days ago (→ an invoice row + a payment row),
        // and a credit note issued 1 day ago — newest first: credit note, payment, invoice.
        Invoice::query()->create([
            'organization_id' => 'org_hist', 'seller' => 'seller_x', 'number' => 'CBOX-H-000001',
            'currency' => 'DKK', 'subtotal_minor' => 20_000, 'tax_minor' => 5_000, 'total_minor' => 25_000,
            'status' => 'paid', 'issued_at' => Carbon::now()->subDays(3), 'paid_at' => Carbon::now()->subDays(2),
            'gateway_reference' => 'pi_test_123',
        ]);
        CreditNote::query()->create([
            'number' => 'CN-000001', 'invoice_number' => 'CBOX-H-000001', 'organization_id' => 'org_hist',
            'seller' => 'seller_x', 'currency' => 'DKK', 'net_minor' => 8_000, 'tax_minor' => 2_000,
            'gross_minor' => 10_000, 'reason' => 'Goodwill', 'kind' => 'adjustment', 'issued_at' => Carbon::now()->subDay(),
        ]);

        $this->get('/billing/portal/'.$token)
            ->assertOk()
            ->assertSee('Billing history')
            ->assertSee('Payment received')
            ->assertSee('pi_test_123')
            ->assertSeeInOrder(['CN-000001', 'Payment received', 'CBOX-H-000001']);
    }

    // --- 4. Notification preferences -------------------------------------------------------

    public function test_opting_out_of_an_optional_mail_suppresses_it_while_a_mandatory_mail_still_sends(): void
    {
        $subscription = $this->subscribe('org_notify', 'team', seats: 1);
        $subscription->loadMissing('organization', 'plan');
        $organization = $subscription->organization;

        Mail::fake();

        // Opt out of the OPTIONAL renewal reminder.
        app(ManagesNotificationPreferences::class)->setOptedIn('org_notify', MailEventType::RenewalReminder, false);

        app(NotifiesCustomers::class)->renewalReminder($subscription);
        Mail::assertNotQueued(RenewalReminderMail::class);

        // A MANDATORY mail (past-due / dunning) ignores the preference and still sends.
        app(NotifiesCustomers::class)->dunningNotice($organization, Money::ofMinor(12_500, 'DKK'), false, null);
        Mail::assertQueued(PaymentFailedMail::class);
    }

    public function test_the_default_optional_mail_still_sends_when_opted_in(): void
    {
        $subscription = $this->subscribe('org_optin', 'team', seats: 1);
        $subscription->loadMissing('organization', 'plan');

        Mail::fake();

        // No preference row → default opted-in → the reminder sends.
        app(NotifiesCustomers::class)->renewalReminder($subscription);
        Mail::assertQueued(RenewalReminderMail::class);
    }

    public function test_the_portal_toggle_persists_a_preference_and_rejects_a_mandatory_event(): void
    {
        $this->subscribe('org_toggle', 'team', seats: 1);
        $token = $this->portalToken('org_toggle');

        // Flip an optional event off — persisted, and the refreshed snapshot reflects it.
        $this->postJson('/billing/portal/'.$token.'/notifications', ['event' => 'renewal_reminder', 'opted_in' => false])
            ->assertOk()
            ->assertJsonPath('optional.renewal_reminder', false);
        $this->assertDatabaseHas('notification_preferences', [
            'organization_id' => 'org_toggle', 'event_type' => 'renewal_reminder', 'opted_in' => false,
        ]);

        // A mandatory/legal event can never be toggled off through the portal (422).
        $this->postJson('/billing/portal/'.$token.'/notifications', ['event' => 'invoice_issued', 'opted_in' => false])
            ->assertStatus(422);
        $this->assertDatabaseMissing('notification_preferences', ['event_type' => 'invoice_issued']);
    }

    public function test_the_notification_card_lists_optional_toggles_and_mandatory_always_on(): void
    {
        $this->subscribe('org_notifcard', 'team', seats: 1);

        $this->get('/billing/portal/'.$this->portalToken('org_notifcard'))
            ->assertOk()
            ->assertSee('Email notifications')
            ->assertSee('Renewal reminder')
            ->assertSee('Always sent')
            ->assertSee('Invoice issued');
    }

    public function test_the_preference_snapshot_defaults_to_opted_in(): void
    {
        $this->subscribe('org_snap', 'team', seats: 1);

        $snapshot = app(ManagesNotificationPreferences::class)->snapshot('org_snap');
        $this->assertTrue($snapshot['renewal_reminder']);
        $this->assertTrue($snapshot['payment_receipt']);

        // Absence of a row is the "opted in" answer — no row is written by reading.
        $this->assertSame(0, NotificationPreference::query()->count());
    }
}
