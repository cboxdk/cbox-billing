<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CboxIdAccessGrant;
use App\Models\Organization;
use App\Models\SeatAssignment;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Cbox ID provisioning-webhook receiver under the purchased + explicitly-assigned seat
 * model: HMAC verify, tampered-signature refusal, replay no-op, deny-by-default when
 * unconfigured, and each event mutating the access mirror (ELIGIBILITY) + seat assignments
 * + org standing — WITHOUT ever touching the billed (purchased) quantity. The SDK runs
 * handlers on its queued job — QUEUE_CONNECTION=sync in tests, so the effect lands inline.
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
        // Manual assignment is the default; the auto-assign tests opt in explicitly.
        config()->set('billing.seats.auto_assign', false);
        config()->set('billing.seats.auto_assign_roles', ['billing-admin', 'billing-operator']);
    }

    public function test_member_added_with_auto_assign_off_adds_eligibility_only(): void
    {
        $purchasedBefore = $this->purchasedOf(self::ORG);

        $response = $this->send('organization.member_added', 'del_1', [
            'organization_id' => self::ORG,
            'user_id' => 'user_alice',
            'role' => 'billing-operator',
        ]);

        $response->assertOk()->assertJson(['received' => true, 'queued' => true]);

        // Eligibility mirrored.
        $this->assertDatabaseHas('cbox_id_access_grants', [
            'organization_id' => self::ORG,
            'subject' => 'user_alice',
            'role' => 'billing-operator',
        ]);
        // Billed quantity unchanged (membership never nudges purchased seats), member is Light.
        $this->assertSame($purchasedBefore, $this->purchasedOf(self::ORG));
        $this->assertFalse($this->isSeated(self::ORG, 'user_alice'));
        $this->assertDatabaseHas('cbox_id_webhook_deliveries', ['delivery_id' => 'del_1']);
    }

    public function test_member_added_with_auto_assign_on_and_a_free_seat_auto_assigns_a_management_role(): void
    {
        config()->set('billing.seats.auto_assign', true);

        $purchasedBefore = $this->purchasedOf(self::ORG);
        $this->assertGreaterThan($this->assignedOf(self::ORG), $purchasedBefore, 'precondition: a free seat exists');

        $this->send('organization.member_added', 'del_auto', [
            'organization_id' => self::ORG, 'user_id' => 'user_auto', 'role' => 'billing-operator',
        ])->assertOk();

        // Auto-assigned a FREE purchased seat (source auto); purchased count intact.
        $this->assertDatabaseHas('seat_assignments', [
            'organization_id' => self::ORG, 'subject' => 'user_auto', 'source' => 'auto',
        ]);
        $this->assertSame($purchasedBefore, $this->purchasedOf(self::ORG));
    }

    public function test_member_added_with_auto_assign_on_but_a_non_management_role_stays_light(): void
    {
        config()->set('billing.seats.auto_assign', true);

        $this->send('organization.member_added', 'del_viewer', [
            'organization_id' => self::ORG, 'user_id' => 'user_viewer', 'role' => 'billing-viewer',
        ])->assertOk();

        // billing-viewer is not in auto_assign_roles → eligibility only, no seat.
        $this->assertDatabaseHas('cbox_id_access_grants', ['subject' => 'user_viewer', 'role' => 'billing-viewer']);
        $this->assertFalse($this->isSeated(self::ORG, 'user_viewer'));
    }

    public function test_member_added_with_auto_assign_on_and_no_free_seat_stays_light(): void
    {
        config()->set('billing.seats.auto_assign', true);

        // Fill the org: set purchased down to exactly the assigned count so no seat is free.
        $subscription = Subscription::query()->where('organization_id', self::ORG)->serving()->firstOrFail();
        $subscription->forceFill(['seats' => $this->assignedOf(self::ORG)])->save();
        $purchasedBefore = $this->purchasedOf(self::ORG);

        $this->send('organization.member_added', 'del_full', [
            'organization_id' => self::ORG, 'user_id' => 'user_full', 'role' => 'billing-admin',
        ])->assertOk();

        // No free seat → the member stays Light; never auto-buys, purchased unchanged.
        $this->assertFalse($this->isSeated(self::ORG, 'user_full'));
        $this->assertSame($purchasedBefore, $this->purchasedOf(self::ORG));
    }

    public function test_member_removed_frees_the_assignment_but_keeps_the_purchased_count(): void
    {
        config()->set('billing.seats.auto_assign', true);

        // A joining management-role member auto-takes a free seat.
        $this->send('organization.member_added', 'del_join', [
            'organization_id' => self::ORG, 'user_id' => 'user_dave', 'role' => 'billing-operator',
        ])->assertOk();
        $this->assertTrue($this->isSeated(self::ORG, 'user_dave'));
        $purchasedAfterJoin = $this->purchasedOf(self::ORG);

        $this->send('organization.member_removed', 'del_leave', [
            'organization_id' => self::ORG, 'user_id' => 'user_dave',
        ])->assertOk();

        // Eligibility gone and the seat freed for reuse — but the purchased count is intact
        // (the org keeps the seat it paid for until an admin explicitly releases it).
        $this->assertDatabaseMissing('cbox_id_access_grants', ['subject' => 'user_dave']);
        $this->assertFalse($this->isSeated(self::ORG, 'user_dave'));
        $this->assertSame($purchasedAfterJoin, $this->purchasedOf(self::ORG));
    }

    public function test_a_role_dropping_out_of_the_auto_assign_set_releases_only_an_auto_seat(): void
    {
        config()->set('billing.seats.auto_assign', true);

        // Auto seat under a management role.
        $this->send('organization.member_added', 'del_role_a', [
            'organization_id' => self::ORG, 'user_id' => 'user_role', 'role' => 'billing-operator',
        ])->assertOk();
        $this->assertTrue($this->isSeated(self::ORG, 'user_role'));

        // The role changes to a non-seat-worthy one → the AUTO seat is released.
        $this->send('organization.member_role_changed', 'del_role_b', [
            'organization_id' => self::ORG, 'user_id' => 'user_role', 'role' => 'billing-viewer',
        ])->assertOk();

        $this->assertFalse($this->isSeated(self::ORG, 'user_role'));
        $this->assertDatabaseHas('cbox_id_access_grants', ['subject' => 'user_role', 'role' => 'billing-viewer']);
    }

    public function test_a_manual_seat_is_never_auto_released_on_a_role_drop(): void
    {
        config()->set('billing.seats.auto_assign', true);

        // Mirror + a MANUAL seat.
        CboxIdAccessGrant::query()->create(['organization_id' => self::ORG, 'subject' => 'user_manual', 'role' => 'billing-operator']);
        SeatAssignment::query()->create(['organization_id' => self::ORG, 'subject' => 'user_manual', 'source' => 'manual']);

        $this->send('organization.member_role_changed', 'del_manual', [
            'organization_id' => self::ORG, 'user_id' => 'user_manual', 'role' => 'billing-viewer',
        ])->assertOk();

        // The manual seat survives the role drop (only auto seats are auto-released).
        $this->assertTrue($this->isSeated(self::ORG, 'user_manual'));
    }

    public function test_a_tampered_signature_is_refused_and_nothing_mutates(): void
    {
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
        $this->assertFalse($this->isSeated(self::ORG, 'user_mallory'));
    }

    public function test_a_replayed_delivery_is_a_no_op(): void
    {
        config()->set('billing.seats.auto_assign', true);

        $this->send('organization.member_added', 'del_replay', [
            'organization_id' => self::ORG, 'user_id' => 'user_bob', 'role' => 'billing-operator',
        ])->assertOk();

        $assignedAfterFirst = $this->assignedOf(self::ORG);

        // Same delivery id again — verified, enqueued, but the sync dedups before applying.
        $this->send('organization.member_added', 'del_replay', [
            'organization_id' => self::ORG, 'user_id' => 'user_bob', 'role' => 'billing-operator',
        ])->assertOk();

        $this->assertSame($assignedAfterFirst, $this->assignedOf(self::ORG));
        $this->assertSame(1, CboxIdAccessGrant::query()->where('subject', 'user_bob')->count());
        $this->assertSame(1, SeatAssignment::query()->where('subject', 'user_bob')->count());
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

    public function test_directory_provisioned_creates_a_bare_membership_without_a_seat(): void
    {
        $purchasedBefore = $this->purchasedOf(self::ORG);

        $this->send('directory.user.provisioned', 'del_scim', [
            'organization_id' => self::ORG, 'user_id' => 'user_scim',
        ])->assertOk();

        $this->assertDatabaseHas('cbox_id_access_grants', [
            'organization_id' => self::ORG, 'subject' => 'user_scim', 'role' => CboxIdAccessGrant::NO_ROLE,
        ]);
        $this->assertFalse($this->isSeated(self::ORG, 'user_scim'));
        $this->assertSame($purchasedBefore, $this->purchasedOf(self::ORG));
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

    /** The PURCHASED (billed) seat count — the serving subscription's quantity. */
    private function purchasedOf(string $org): int
    {
        return Subscription::query()->where('organization_id', $org)->serving()->firstOrFail()->seats;
    }

    /** The number of purchased seats currently assigned to members. */
    private function assignedOf(string $org): int
    {
        return SeatAssignment::query()->where('organization_id', $org)->count();
    }

    private function isSeated(string $org, string $subject): bool
    {
        return SeatAssignment::query()->where('organization_id', $org)->where('subject', $subject)->exists();
    }
}
