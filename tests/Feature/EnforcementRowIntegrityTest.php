<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A row the enforcement API accepted must never vanish from the batch.
 *
 * The regression: `integer` validation runs through `filter_var`, which accepts the JSON string
 * `"5"`, and the controllers then re-checked with `is_int()` and `continue`d past anything that
 * failed. A client that stringifies numbers therefore got `200 {"outcome":"allowed"}` for a
 * meter that was never checked, and `{"accepted":0}` on ingest — unenforced and unbilled, with
 * a success status and no signal at any layer. Silent under-billing is the worst possible
 * failure mode for a metering API, so the contract is now: interpret the row, or refuse the
 * request. Never drop it.
 */
class EnforcementRowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = 'org_row_integrity';

    /** @return array<string, string> */
    private function auth(): array
    {
        Organization::query()->create(['id' => self::ORG, 'name' => 'Row Integrity']);

        ['plaintext' => $token] = ApiToken::issue('row-integrity', self::ORG);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_a_stringified_estimate_is_honoured_rather_than_dropped_from_the_reservation(): void
    {
        $response = $this->postJson('/api/v1/reserve', [
            'org' => self::ORG,
            'meters' => [['meter' => 'api.requests', 'estimate' => '5']],
        ], $this->auth());

        $response->assertOk();

        // This org has no such meter configured, so an estimate that REACHES the engine is
        // evaluated and refused as `unknown_meter`. That refusal is the proof: under the old
        // code the stringified estimate was dropped before it got there, leaving an empty
        // reservation that was trivially `allowed`. "allowed" here would mean nothing was
        // checked — which is precisely the silent failure this test exists to catch.
        $this->assertSame('denied', $response->json('outcome'));
        $this->assertSame('unknown_meter', $response->json('reason'));
    }

    public function test_a_stringified_cumulative_is_ingested_rather_than_dropped(): void
    {
        $response = $this->postJson('/api/v1/usage', [
            'org' => self::ORG,
            'entries' => [['meter' => 'api.requests', 'cumulative' => '100', 'seq' => '1']],
        ], $this->auth());

        $response->assertOk();

        // The whole point: a stringified reading is real usage. Accepting the request and
        // reporting `accepted: 0` is the silent under-bill this test exists to prevent.
        $this->assertSame(1, $response->json('accepted'));
    }

    public function test_an_uninterpretable_row_is_refused_instead_of_silently_skipped(): void
    {
        $this->postJson('/api/v1/usage', [
            'org' => self::ORG,
            'entries' => [['meter' => 'api.requests', 'cumulative' => 'not-a-number', 'seq' => 1]],
        ], $this->auth())->assertStatus(422);
    }

    public function test_a_batch_never_reports_success_while_discarding_part_of_itself(): void
    {
        $response = $this->postJson('/api/v1/usage', [
            'org' => self::ORG,
            'entries' => [
                ['meter' => 'api.requests', 'cumulative' => 10, 'seq' => 1],
                ['meter' => 'api.storage', 'cumulative' => '20', 'seq' => '2'],
            ],
        ], $this->auth());

        $response->assertOk();
        $this->assertSame(2, $response->json('accepted'), 'Every accepted row must be counted.');
    }
}
