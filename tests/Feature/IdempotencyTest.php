<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Licensing\DatabaseIssuedLicenseStore;
use App\Models\ApiToken;
use App\Models\IdempotencyKey;
use App\Models\Organization;
use Cbox\License\Support\Ed25519KeyPair;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LicensingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Request idempotency on the mutating management endpoints. A retried write carrying the same
 * `Idempotency-Key` produces exactly one effect and replays the first response; a key reused
 * with a different payload is a conflict; and without the header each call is independent.
 *
 * License issue is the probe: each un-keyed issue mints a NEW license, so a duplicate is
 * observable in the store.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{publicKey: string, privateKey: string} */
    private array $keyPair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyPair = Ed25519KeyPair::generate();
        config([
            'billing.licensing.signing_key' => $this->keyPair['privateKey'],
            'billing.licensing.public_key' => $this->keyPair['publicKey'],
        ]);

        $this->seed(CatalogSeeder::class);
        $this->seed(LicensingSeeder::class);

        Organization::query()->create(['id' => 'org_idem', 'name' => 'Idem Co', 'billing_country' => 'DK']);
    }

    public function test_same_key_twice_produces_one_effect_and_replays_the_response(): void
    {
        $auth = $this->operatorAuth();

        $first = $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth + ['Idempotency-Key' => 'key-1']);
        $first->assertCreated();
        $firstId = $first->json('id');

        $second = $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth + ['Idempotency-Key' => 'key-1']);
        $second->assertCreated();

        // Same license id replayed, flagged as a replay — and only ONE license exists.
        $this->assertSame($firstId, $second->json('id'));
        $second->assertHeader('Idempotency-Replayed', 'true');
        $this->assertCount(1, app(DatabaseIssuedLicenseStore::class)->forCustomer('org_idem'));
    }

    public function test_a_different_key_creates_a_second_effect(): void
    {
        $auth = $this->operatorAuth();

        $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth + ['Idempotency-Key' => 'key-a'])->assertCreated();
        $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth + ['Idempotency-Key' => 'key-b'])->assertCreated();

        $this->assertCount(2, app(DatabaseIssuedLicenseStore::class)->forCustomer('org_idem'));
    }

    public function test_without_a_key_each_request_is_independent(): void
    {
        $auth = $this->operatorAuth();

        $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth)->assertCreated();
        $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth)->assertCreated();

        $this->assertCount(2, app(DatabaseIssuedLicenseStore::class)->forCustomer('org_idem'));
    }

    public function test_reusing_a_key_with_a_different_payload_is_a_conflict(): void
    {
        $auth = $this->operatorAuth();

        $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth + ['Idempotency-Key' => 'key-x'])->assertCreated();

        $conflict = $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'team-onprem'], $auth + ['Idempotency-Key' => 'key-x']);
        $conflict->assertStatus(409);

        // The conflicting second request had no effect — still exactly one license.
        $this->assertCount(1, app(DatabaseIssuedLicenseStore::class)->forCustomer('org_idem'));
    }

    public function test_the_signed_license_key_is_never_stored_in_the_idempotency_record(): void
    {
        $auth = $this->operatorAuth();

        $first = $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth + ['Idempotency-Key' => 'sec2-key']);
        $first->assertCreated();

        // The original caller DOES receive the signed key on its first 2xx.
        $key = $first->json('key');
        $this->assertIsString($key);
        $this->assertNotSame('', $key);

        // But it is redacted from the persisted replay copy (SEC-2): the row carries the
        // response shape (`key` present, but null) and NOT the secret artifact.
        $record = IdempotencyKey::query()->where('idempotency_key', 'sec2-key')->firstOrFail();
        $this->assertIsString($record->response_body);
        $this->assertStringNotContainsString($key, $record->response_body);
        $this->assertStringContainsString('"key":null', $record->response_body);

        // The replay is redacted too — the secret is not replayable, only the original delivery.
        $replay = $this->postJson('/api/v1/licenses', ['customer_id' => 'org_idem', 'plan' => 'enterprise-onprem'], $auth + ['Idempotency-Key' => 'sec2-key']);
        $replay->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertNull($replay->json('key'));
        $this->assertSame($first->json('id'), $replay->json('id'));
    }

    public function test_prune_drops_expired_records_and_reaps_poisoned_claims(): void
    {
        // A fresh, completed record and a fresh, still-in-flight claim both survive.
        IdempotencyKey::query()->create([
            'idempotency_key' => 'fresh-done', 'scope' => 's', 'method' => 'POST', 'path' => 'p',
            'request_hash' => 'h', 'response_status' => 201, 'response_body' => '{}',
        ]);
        IdempotencyKey::query()->create([
            'idempotency_key' => 'fresh-inflight', 'scope' => 's', 'method' => 'POST', 'path' => 'p',
            'request_hash' => 'h', 'response_status' => null, 'response_body' => null,
        ]);

        // An expired completed record (older than the 72h retention) and a poisoned claim
        // (never completed, older than the 60m stale window, L1) are both removed.
        $expired = IdempotencyKey::query()->create([
            'idempotency_key' => 'old-done', 'scope' => 's', 'method' => 'POST', 'path' => 'p',
            'request_hash' => 'h', 'response_status' => 201, 'response_body' => '{}',
        ]);
        $expired->forceFill(['created_at' => Carbon::now()->subHours(96)])->save();

        $poisoned = IdempotencyKey::query()->create([
            'idempotency_key' => 'poisoned', 'scope' => 's', 'method' => 'POST', 'path' => 'p',
            'request_hash' => 'h', 'response_status' => null, 'response_body' => null,
        ]);
        $poisoned->forceFill(['created_at' => Carbon::now()->subHours(3)])->save();

        $this->artisan('billing:prune-idempotency')->assertSuccessful();

        $this->assertDatabaseHas('idempotency_keys', ['idempotency_key' => 'fresh-done']);
        $this->assertDatabaseHas('idempotency_keys', ['idempotency_key' => 'fresh-inflight']);
        $this->assertDatabaseMissing('idempotency_keys', ['idempotency_key' => 'old-done']);
        $this->assertDatabaseMissing('idempotency_keys', ['idempotency_key' => 'poisoned']);
    }

    /** @return array<string, string> */
    private function operatorAuth(): array
    {
        ['plaintext' => $token] = ApiToken::issue('operator', null);

        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * A 5xx must NOT free the claim. "Non-2xx means nothing committed" holds for a refusal but
     * not for a server error — a controller can commit its transaction and then throw in a
     * post-commit listener. Freeing the key there lets the client's retry (the very thing the
     * header exists to make safe) run the mutation twice.
     */
    public function test_a_server_error_keeps_the_claim_so_a_retry_cannot_double_apply(): void
    {
        Route::post('/_test/boom', fn () => response()->json(['error' => 'boom'], 500))
            ->middleware(['api', 'idempotency']);

        $this->postJson('/_test/boom', ['a' => 1], ['Idempotency-Key' => 'k-5xx']);

        $this->assertDatabaseHas('idempotency_keys', [
            'idempotency_key' => 'k-5xx',
            'response_status' => null,
        ]);
    }

    /** A 4xx IS a refusal — nothing committed, so the caller may fix the request and retry. */
    public function test_a_client_refusal_frees_the_key(): void
    {
        Route::post('/_test/refuse', fn () => response()->json(['error' => 'nope'], 422))
            ->middleware(['api', 'idempotency']);

        $this->postJson('/_test/refuse', ['a' => 1], ['Idempotency-Key' => 'k-4xx']);

        $this->assertDatabaseMissing('idempotency_keys', ['idempotency_key' => 'k-4xx']);
    }

    /**
     * A thrown exception is ambiguous in exactly the same way a 5xx is — the controller may have
     * committed and then thrown in a post-commit listener — so the claim is KEPT for the stale
     * reaper rather than freed. Freeing it would let the client's retry double-apply.
     */
    public function test_a_thrown_exception_keeps_the_claim_for_the_reaper(): void
    {
        Route::post('/_test/throw', function (): never {
            throw new RuntimeException('kaboom');
        })->middleware(['api', 'idempotency']);

        try {
            $this->withoutExceptionHandling()
                ->postJson('/_test/throw', ['a' => 1], ['Idempotency-Key' => 'k-throw']);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseHas('idempotency_keys', [
            'idempotency_key' => 'k-throw',
            'response_status' => null,
        ]);
    }
}
