<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Api\ApiIdentity;
use App\Billing\Approvals\Actions\RefundInvoiceAction;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Invoicing\Contracts\RunsInvoiceOperations;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\CycleRenewalService;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use App\Models\Invoice;
use App\Models\OperatorAuditEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Cbox\License\Support\Ed25519KeyPair;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\VaultPaymentGateway;
use Tests\TestCase;

/**
 * Audit coverage for the token-authed API + scheduled/system surfaces (platform-review P1 #2):
 * the tamper-evident operator audit was attached ONLY to the console group, so token-API
 * mutations and scheduled runs recorded nothing. This pins that an API-token license-revoke,
 * payment-method-detach and subscription mutation each append exactly one event under the
 * TOKEN identity, a token-context refund records under the token, and a scheduled renewal
 * records under the `system` actor — every one on the same hash-chained trail.
 */
class ApiAndSystemAuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-07-18 10:00:00');

        // A real Ed25519 pair so the licensing service (and its revoke path) constructs.
        $keyPair = Ed25519KeyPair::generate();
        config([
            'billing.licensing.signing_key' => $keyPair['privateKey'],
            'billing.licensing.public_key' => $keyPair['publicKey'],
        ]);
    }

    private function org(string $id): Organization
    {
        return Organization::query()->create([
            'id' => $id, 'name' => ucfirst($id), 'billing_email' => $id.'@example.test',
            'billing_country' => 'DK', 'billing_currency' => 'DKK',
        ]);
    }

    public function test_an_api_token_license_revoke_appends_one_event_under_the_token_identity(): void
    {
        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::issue('ops-sdk', null);
        $auth = ['Authorization' => 'Bearer '.$plaintext];

        $this->postJson('/api/v1/licenses/lic_acme/revoke', ['reason' => 'compromised'], $auth)
            ->assertOk()
            ->assertJsonPath('revoked', true);

        $events = OperatorAuditEvent::query()->where('action', AuditAction::LicenseRevoked->value)->get();
        $this->assertCount(1, $events);

        $event = $events->firstOrFail();
        $this->assertSame('api-token:'.$token->id, $event->actor_sub);
        $this->assertSame('ops-sdk', $event->actor_name);
        $this->assertSame('license', $event->target_type);
        $this->assertSame('lic_acme', $event->target_id);
    }

    public function test_an_api_token_payment_method_detach_appends_one_event_under_the_token_identity(): void
    {
        $gateway = new VaultPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        $organization = $this->org('org_pm');
        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::issue('org-sdk', 'org_pm');
        $auth = ['Authorization' => 'Bearer '.$plaintext];

        $account = app(ResolvesGatewayCustomer::class)->resolve($organization);
        $gateway->attachPaymentMethod($account, 'pm_one');

        $this->deleteJson('/api/v1/payment-methods/org_pm/pm_one', [], $auth)->assertNoContent();

        $events = OperatorAuditEvent::query()->where('action', AuditAction::CustomerPaymentMethodRemoved->value)->get();
        $this->assertCount(1, $events);

        $event = $events->firstOrFail();
        $this->assertSame('api-token:'.$token->id, $event->actor_sub);
        $this->assertSame('organization', $event->target_type);
        $this->assertSame('org_pm', $event->organization_id);
        $this->assertSame('pm_one', $event->metadata['payment_method_id'] ?? null);
    }

    public function test_an_api_token_subscription_mutation_is_covered_by_the_central_seam(): void
    {
        $organization = $this->org('org_sub');
        $plan = Plan::query()->where('key', 'team')->firstOrFail();
        app(SubscribesOrganizations::class)->subscribe($organization, $plan, 3);

        ['token' => $token, 'plaintext' => $plaintext] = ApiToken::issue('sub-sdk', 'org_sub');
        $auth = ['Authorization' => 'Bearer '.$plaintext];

        // A token-API cancel is NOT instrumented in a controller/service — the central audit
        // seam now attached to the management group records it via the route→action map.
        $this->postJson('/api/v1/subscriptions/org_sub/cancel', ['at_period_end' => false], $auth)->assertOk();

        $events = OperatorAuditEvent::query()->where('action', AuditAction::SubscriptionCanceled->value)->get();
        $this->assertCount(1, $events);

        $event = $events->firstOrFail();
        $this->assertSame('api-token:'.$token->id, $event->actor_sub);
        $this->assertSame('org_sub', $event->organization_id);
    }

    public function test_a_token_context_refund_records_under_the_token_actor(): void
    {
        // There is no token-API refund endpoint (refunds run through the console approval gate);
        // this pins that the SHARED refund path resolves the token identity when the ambient
        // actor is an API credential, so an API-driven refund would be attributed correctly.
        $invoice = $this->invoicedOrg('org_refund');

        request()->attributes->set(
            AuthenticateApiToken::ATTRIBUTE,
            ApiIdentity::operator(actorSub: 'api-token:77', actorName: 'refund-sdk'),
        );

        $action = new RefundInvoiceAction(
            app(RunsInvoiceOperations::class),
            app(RecordsAudit::class),
            $invoice,
            null,
            RefundReason::Requested,
            'refund-api-audit',
        );
        $action->execute();

        $events = OperatorAuditEvent::query()->where('action', AuditAction::InvoiceRefunded->value)->get();
        $this->assertCount(1, $events);

        $event = $events->firstOrFail();
        $this->assertSame('api-token:77', $event->actor_sub);
        $this->assertSame('refund-sdk', $event->actor_name);
        $this->assertSame('invoice', $event->target_type);
        $this->assertSame((string) $invoice->id, $event->target_id);
    }

    public function test_a_scheduled_renewal_records_a_system_actor_event(): void
    {
        $organization = $this->org('org_renew');
        $plan = Plan::query()->where('key', 'team')->firstOrFail();

        $subscription = app(SubscribesOrganizations::class)->subscribe($organization, $plan, 2);

        // Drive the boundary: the current period is now due, so the scheduled renewal rolls it
        // over. No operator session and no API identity are set — this is the unattended path.
        $periodEnd = $subscription->current_period_end;
        $this->assertNotNull($periodEnd);
        Carbon::setTestNow($periodEnd->copy()->addDay());

        $outcome = app(CycleRenewalService::class)->renew($subscription->refresh());
        $this->assertTrue($outcome->baseRenewed);

        $events = OperatorAuditEvent::query()->where('action', AuditAction::SubscriptionRenewed->value)->get();
        $this->assertCount(1, $events);

        $event = $events->firstOrFail();
        $this->assertSame('system', $event->actor_sub);
        $this->assertSame('subscription', $event->target_type);
        $this->assertSame((string) $subscription->id, $event->target_id);
    }

    private function invoicedOrg(string $org): Invoice
    {
        $organization = $this->org($org);
        $plan = Plan::query()->where('key', 'team')->firstOrFail();

        $subscription = Subscription::query()->create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'seats' => 20,
            'current_period_start' => Carbon::parse('2026-07-01', 'UTC'),
            'current_period_end' => Carbon::parse('2026-08-01', 'UTC'),
            'cancel_at_period_end' => false,
        ]);

        return app(GeneratesInvoices::class)->generate($subscription->refresh());
    }
}
