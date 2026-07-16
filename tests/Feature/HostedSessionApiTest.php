<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BillingSession;
use App\Models\Organization;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The hosted-session API (ADR-0009 Path A): opening a checkout- or portal-session returns
 * the `{url}` of a hosted page keyed by an opaque, expiring token. Each call is per-org
 * scoped (a token for org A cannot open a session for org B → 403), token-authenticated,
 * and the session carries a TTL after which its token no longer authorizes the page.
 */
class HostedSessionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    /** @return array<string, string> */
    private function orgWithToken(string $id): array
    {
        Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'billing_email' => $id.'@example.test',
            'billing_country' => 'DK',
        ]);

        ['plaintext' => $token] = ApiToken::issue($id.'-sdk', $id);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_opening_a_checkout_session_returns_a_hosted_url(): void
    {
        $auth = $this->orgWithToken('org_chk');

        $response = $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'org_chk',
            'plan' => 'starter',
            'return_url' => 'https://merchant.example/done',
        ], $auth);

        $response->assertCreated();
        $this->assertStringContainsString('/billing/checkout/', (string) $response->json('url'));
        $this->assertNotNull($response->json('expires_at'));

        $session = BillingSession::query()->where('organization_id', 'org_chk')->firstOrFail();
        $this->assertSame('checkout', $session->type->value);
        $this->assertSame('starter', $session->plan_key);
        $this->assertSame('pending', $session->status->value);
        $this->assertStringEndsWith('/billing/checkout/'.$session->token, (string) $response->json('url'));
    }

    public function test_checkout_session_refuses_a_plan_not_priced_in_the_currency(): void
    {
        $auth = $this->orgWithToken('org_cur');

        $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'org_cur',
            'plan' => 'starter',
            'currency' => 'GBP',
            'return_url' => 'https://merchant.example/done',
        ], $auth)->assertStatus(422);
    }

    public function test_opening_a_portal_session_returns_a_hosted_url(): void
    {
        $auth = $this->orgWithToken('org_pt');

        $response = $this->postJson('/api/v1/portal-sessions', [
            'org' => 'org_pt',
            'return_url' => 'https://merchant.example/account',
        ], $auth);

        $response->assertCreated();
        $this->assertStringContainsString('/billing/portal/', (string) $response->json('url'));

        $this->assertSame('portal', BillingSession::query()->where('organization_id', 'org_pt')->firstOrFail()->type->value);
    }

    public function test_sessions_are_per_org_scoped(): void
    {
        Organization::query()->create(['id' => 'org_a', 'name' => 'A', 'billing_country' => 'DK']);
        Organization::query()->create(['id' => 'org_b', 'name' => 'B', 'billing_country' => 'DK']);

        ['plaintext' => $token] = ApiToken::issue('a-sdk', 'org_a');
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'org_b', 'plan' => 'starter', 'return_url' => 'https://merchant.example/done',
        ], $auth)->assertForbidden();

        $this->postJson('/api/v1/portal-sessions', [
            'org' => 'org_b', 'return_url' => 'https://merchant.example/account',
        ], $auth)->assertForbidden();

        $this->assertSame(0, BillingSession::query()->count());
    }

    public function test_session_endpoints_deny_unauthenticated_requests(): void
    {
        $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'org_x', 'plan' => 'starter', 'return_url' => 'https://merchant.example/done',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/portal-sessions', [
            'org' => 'org_x', 'return_url' => 'https://merchant.example/account',
        ])->assertUnauthorized();
    }

    public function test_an_expired_pending_session_no_longer_opens_its_page(): void
    {
        $auth = $this->orgWithToken('org_exp');

        $url = $this->postJson('/api/v1/checkout-sessions', [
            'org' => 'org_exp', 'plan' => 'starter', 'return_url' => 'https://merchant.example/done',
        ], $auth)->json('url');

        $session = BillingSession::query()->where('organization_id', 'org_exp')->firstOrFail();

        // While pending and within its TTL, the page renders.
        $this->get($url)->assertOk();

        // Age it past its TTL: the token no longer authorizes the page.
        $session->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $this->get($url)->assertNotFound();
        $this->assertSame('expired', $session->refresh()->status->value);
    }
}
