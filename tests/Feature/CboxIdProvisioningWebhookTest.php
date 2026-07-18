<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CboxIdAccessGrant;
use App\Models\Organization;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Cbox ID provisioning-webhook receiver: HMAC verify (happy path), tampered-signature
 * refusal, replay no-op, deny-by-default when unconfigured, and each event mutating the
 * access mirror / seat counts / org standing. The SDK runs handlers on its queued job —
 * QUEUE_CONNECTION=sync in tests, so the effect lands inline.
 */
class CboxIdProvisioningWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_provisioning';

    private const ORG = 'org_hverdag';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
        config()->set('cbox-id-client.webhooks.secret', self::SECRET);
        config()->set('cbox-id-client.webhooks.tolerance', 300);
    }

    public function test_member_added_mirrors_the_grant_and_adds_a_seat(): void
    {
        $before = $this->seatsOf(self::ORG);

        $response = $this->send('organization.member_added', 'del_1', [
            'organization_id' => self::ORG,
            'user_id' => 'user_alice',
            'role' => 'billing-operator',
        ]);

        $response->assertOk()->assertJson(['received' => true, 'queued' => true]);

        $this->assertDatabaseHas('cbox_id_access_grants', [
            'organization_id' => self::ORG,
            'subject' => 'user_alice',
            'role' => 'billing-operator',
        ]);
        $this->assertSame($before + 1, $this->seatsOf(self::ORG));
        $this->assertDatabaseHas('cbox_id_webhook_deliveries', ['delivery_id' => 'del_1']);
    }

    public function test_a_tampered_signature_is_refused_and_nothing_mutates(): void
    {
        $before = $this->seatsOf(self::ORG);

        $body = $this->body('organization.member_added', 'del_forged', [
            'organization_id' => self::ORG, 'user_id' => 'user_mallory', 'role' => 'billing-admin',
        ]);
        $timestamp = time();

        $response = $this->postRaw($body, [
            'X-Cbox-Signature' => "t={$timestamp},v1=".str_repeat('0', 64),
            'X-Cbox-Timestamp' => (string) $timestamp,
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseMissing('cbox_id_access_grants', ['subject' => 'user_mallory']);
        $this->assertSame($before, $this->seatsOf(self::ORG));
    }

    public function test_a_replayed_delivery_is_a_no_op(): void
    {
        $this->send('organization.member_added', 'del_replay', [
            'organization_id' => self::ORG, 'user_id' => 'user_bob', 'role' => 'billing-viewer',
        ])->assertOk();

        $afterFirst = $this->seatsOf(self::ORG);

        // Same delivery id again — verified, enqueued, but the sync dedups before applying.
        $this->send('organization.member_added', 'del_replay', [
            'organization_id' => self::ORG, 'user_id' => 'user_bob', 'role' => 'billing-viewer',
        ])->assertOk();

        $this->assertSame($afterFirst, $this->seatsOf(self::ORG));
        $this->assertSame(1, CboxIdAccessGrant::query()->where('subject', 'user_bob')->count());
        $this->assertSame(1, \DB::table('cbox_id_webhook_deliveries')->where('delivery_id', 'del_replay')->count());
    }

    public function test_deny_by_default_when_no_secret_is_configured(): void
    {
        config()->set('cbox-id-client.webhooks.secret', null);

        $body = $this->body('organization.member_added', 'del_nosecret', [
            'organization_id' => self::ORG, 'user_id' => 'user_x',
        ]);
        $timestamp = time();

        $response = $this->postRaw($body, [
            'X-Cbox-Signature' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET),
            'X-Cbox-Timestamp' => (string) $timestamp,
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseMissing('cbox_id_access_grants', ['subject' => 'user_x']);
    }

    public function test_role_assigned_then_revoked_toggles_the_grant(): void
    {
        // Cbox ID's role.assigned / role.revoked carry the slug under `role_id`.
        $this->send('role.assigned', 'del_role_1', [
            'organization_id' => self::ORG, 'user_id' => 'user_carol', 'role_id' => 'billing-admin',
        ])->assertOk();

        $this->assertDatabaseHas('cbox_id_access_grants', [
            'organization_id' => self::ORG, 'subject' => 'user_carol', 'role' => 'billing-admin',
        ]);

        $this->send('role.revoked', 'del_role_2', [
            'organization_id' => self::ORG, 'user_id' => 'user_carol', 'role_id' => 'billing-admin',
        ])->assertOk();

        $this->assertDatabaseMissing('cbox_id_access_grants', [
            'organization_id' => self::ORG, 'subject' => 'user_carol', 'role' => 'billing-admin',
        ]);
    }

    public function test_member_removed_drops_grants_and_releases_a_seat(): void
    {
        $this->send('organization.member_added', 'del_add', [
            'organization_id' => self::ORG, 'user_id' => 'user_dave', 'role' => 'billing-operator',
        ])->assertOk();
        $afterAdd = $this->seatsOf(self::ORG);

        $this->send('organization.member_removed', 'del_remove', [
            'organization_id' => self::ORG, 'user_id' => 'user_dave',
        ])->assertOk();

        $this->assertDatabaseMissing('cbox_id_access_grants', ['subject' => 'user_dave']);
        $this->assertSame($afterAdd - 1, $this->seatsOf(self::ORG));
    }

    public function test_directory_provisioned_creates_a_bare_membership_without_a_seat(): void
    {
        $before = $this->seatsOf(self::ORG);

        $this->send('directory.user.provisioned', 'del_scim', [
            'organization_id' => self::ORG, 'user_id' => 'user_scim',
        ])->assertOk();

        $this->assertDatabaseHas('cbox_id_access_grants', [
            'organization_id' => self::ORG, 'subject' => 'user_scim', 'role' => CboxIdAccessGrant::NO_ROLE,
        ]);
        $this->assertSame($before, $this->seatsOf(self::ORG));
    }

    public function test_org_suspend_and_reactivate_toggle_the_marker(): void
    {
        $this->send('organization.suspended', 'del_susp', ['organization_id' => self::ORG])->assertOk();
        $this->assertNotNull(Organization::query()->findOrFail(self::ORG)->suspended_at);

        $this->send('organization.reactivated', 'del_react', ['organization_id' => self::ORG])->assertOk();
        $this->assertNull(Organization::query()->findOrFail(self::ORG)->suspended_at);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $type, string $deliveryId, array $data): TestResponse
    {
        $body = $this->body($type, $deliveryId, $data);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);

        return $this->postRaw($body, [
            'X-Cbox-Signature' => "t={$timestamp},v1={$signature}",
            'X-Cbox-Timestamp' => (string) $timestamp,
        ]);
    }

    /**
     * POST a raw (already-serialized) body with explicit headers. Headers go through the
     * server vars — the comma in `X-Cbox-Signature` survives, and the raw bytes reach the
     * SDK verifier unchanged.
     *
     * @param  array<string, string>  $headers
     */
    private function postRaw(string $body, array $headers): TestResponse
    {
        $server = $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']);

        return $this->call('POST', '/webhooks/cbox-id', [], [], [], $server, $body);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function body(string $type, string $deliveryId, array $data): string
    {
        return json_encode([
            'type' => $type,
            'delivery_id' => $deliveryId,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function seatsOf(string $org): int
    {
        return Subscription::query()->where('organization_id', $org)->serving()->firstOrFail()->seats;
    }
}
