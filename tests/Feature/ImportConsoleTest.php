<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ImportRun;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\ImportsFixtures;
use Tests\TestCase;

/**
 * The Import console area: upload → dry-run report → commit → per-run log, gated `settings:manage`.
 */
class ImportConsoleTest extends TestCase
{
    use ImportsFixtures;
    use RefreshDatabase;

    /** @param list<string> $permissions */
    private function signedInWith(array $permissions = ['settings:manage', 'settings:read']): self
    {
        $this->withSession(['auth.user' => [
            'sub' => 'demo|operator', 'name' => 'Test Operator', 'email' => 'ops@example.test',
            'org' => 'org_hverdag', 'picture' => null, 'permissions' => $permissions,
        ]]);

        return $this;
    }

    private function stripePayload(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/imports/stripe.json'));
    }

    public function test_index_renders(): void
    {
        $this->signedInWith()->get('/import')
            ->assertOk()
            ->assertSee('Import &amp; migration', false)
            ->assertSee('Stripe');
    }

    public function test_preview_then_commit_then_log(): void
    {
        // Dry-run from a pasted combined-JSON export.
        $this->signedInWith()->post('/import/preview', [
            'source' => 'stripe',
            'payload' => $this->stripePayload(),
        ])->assertOk()->assertSee('Dry-run');

        // The dry-run created a planned run and wrote nothing.
        $run = ImportRun::query()->latest('id')->firstOrFail();
        $this->assertTrue((bool) $run->dry_run);
        $this->assertSame(0, Organization::query()->count());

        // Commit it.
        $this->signedInWith()->post("/import/{$run->id}/commit", ['mapping' => []])
            ->assertRedirect(route('billing.import.runs.show', $run->id));

        $this->assertTrue($run->refresh()->isCommitted());
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(1, Subscription::query()->count());
        $this->assertGreaterThan(0, $run->entries()->count());

        // The per-run log renders the source→app mapping.
        $this->signedInWith()->get(route('billing.import.runs.show', $run->id))
            ->assertOk()
            ->assertSee('cus_ann')
            ->assertSee('in_ann_1');
    }

    public function test_a_wrong_mime_upload_is_refused_before_any_parse(): void
    {
        $this->signedInWith()->post('/import/preview', [
            'source' => 'stripe',
            'files' => [UploadedFile::fake()->create('export.php', 4, 'application/x-php')],
        ])->assertSessionHasErrors('files.0');

        // Refused up front: nothing was staged.
        $this->assertSame(0, ImportRun::query()->count());
    }

    public function test_an_oversized_upload_is_refused_before_any_parse(): void
    {
        // 10 MB + 1 KB, just over the ceiling.
        $this->signedInWith()->post('/import/preview', [
            'source' => 'stripe',
            'files' => [UploadedFile::fake()->create('export.json', 10_240 + 1)],
        ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, ImportRun::query()->count());
    }

    public function test_a_deeply_nested_payload_is_refused_before_any_parse(): void
    {
        // A JSON document nested well past the parse depth cap.
        $deep = str_repeat('[', 200).str_repeat(']', 200);

        $this->signedInWith()->post('/import/preview', [
            'source' => 'stripe',
            'payload' => $deep,
        ])->assertSessionHasErrors('files');

        $this->assertSame(0, ImportRun::query()->count());
    }

    public function test_import_requires_authentication(): void
    {
        $this->get('/import')->assertRedirect();
    }
}
