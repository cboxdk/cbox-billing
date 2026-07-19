<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Billing\Licensing\LicenseReport;
use App\Models\Organization;
use Cbox\License\Support\Ed25519KeyPair;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LicensingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-4: the Licenses list paginates AT THE DATABASE (only the visible page is hydrated), and
 * the derived status resolves against a revocation set loaded ONCE — never a per-license
 * `exists()` round trip, and never a second full load for the counts.
 */
class LicenseReportPerfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = Ed25519KeyPair::generate();
        config([
            'billing.licensing.signing_key' => $keyPair['privateKey'],
            'billing.licensing.public_key' => $keyPair['publicKey'],
        ]);

        $this->seed(CatalogSeeder::class);
        $this->seed(LicensingSeeder::class);
        Organization::query()->create(['id' => 'org_lic', 'name' => 'Lic Co', 'billing_country' => 'DK']);
    }

    public function test_the_list_paginates_at_the_db_and_reads_revocations_once(): void
    {
        $licenses = app(IssuesLicenses::class);

        for ($i = 0; $i < 5; $i++) {
            $licenses->issue(customerId: 'org_lic', planId: 'enterprise-onprem', deploymentId: 'dep_'.$i);
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $page = app(LicenseReport::class)->paginate(null, 2);

        // Two rows on the page; the total still reflects all five issued licenses.
        $this->assertCount(2, $page->items());
        $this->assertSame(5, $page->total());

        // The revocation registry is consulted ONCE for the whole page (the set is loaded and
        // checked in memory), not once per license.
        $revocationReads = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "license_revocations"')));
        $this->assertSame(1, $revocationReads);
    }

    public function test_counts_use_a_single_license_query_and_one_revocation_read(): void
    {
        $licenses = app(IssuesLicenses::class);

        for ($i = 0; $i < 4; $i++) {
            $licenses->issue(customerId: 'org_lic', planId: 'enterprise-onprem', deploymentId: 'dep_c'.$i);
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $counts = app(LicenseReport::class)->counts();

        $this->assertSame(4, $counts['all']);

        $licenseReads = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "issued_licenses"')));
        $revocationReads = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "license_revocations"')));

        // One aggregate read of the licenses + one of the revocations — not a per-license scan.
        $this->assertSame(1, $licenseReads);
        $this->assertSame(1, $revocationReads);
    }
}
