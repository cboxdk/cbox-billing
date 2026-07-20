<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Audit\Contracts\AssemblesDsarBundle;
use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Export\DataExporter;
use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Encoders\RowEncoderFactory;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\Invoice;
use App\Models\Organization;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PharData;
use Tests\TestCase;

/**
 * The export and DSAR read surfaces partition by the current ENVIRONMENT key, not the binary
 * livemode — so an export or a DSAR built in one named sandbox never includes ANOTHER named
 * sandbox's rows, even for the same organization id.
 */
class ExportDsarEnvironmentPartitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);
        app(CreatesEnvironments::class)->create(key: 'sbx-a');
        app(CreatesEnvironments::class)->create(key: 'sbx-b');

        // The org PK is global (one row), satisfying the invoices FK from either plane; the child
        // invoices, keyed by the org id string, are what co-mingle across planes.
        $this->inEnvironment('sbx-a', fn () => Organization::query()->create(['id' => 'org_shared', 'name' => 'Shared', 'billing_email' => 's@example.test']));
    }

    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return app(BillingContext::class)->runInEnvironment($environment, $callback);
    }

    /**
     * A distinct invoice for the SAME organization_id string in each named sandbox. The org row's
     * PK is global, so it lives in one plane; the child invoices, keyed by the org id string, are
     * what realistically co-mingle across planes — exactly what the partition must isolate.
     */
    private function seedInvoice(string $environmentKey, string $number): void
    {
        $this->inEnvironment($environmentKey, function () use ($number): void {
            Invoice::query()->create([
                'organization_id' => 'org_shared', 'seller' => 's', 'number' => $number, 'currency' => 'DKK',
                'subtotal_minor' => 100, 'tax_minor' => 0, 'total_minor' => 100, 'status' => 'paid',
                'issued_at' => Carbon::parse('2026-06-01'),
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function ndjson(string $dataset, ExportQuery $query): array
    {
        $out = '';
        app(DataExporter::class)->pump(
            app(DatasetRegistry::class)->get($dataset),
            app(RowEncoderFactory::class)->for(ExportFormat::Ndjson),
            $query,
            function (string $chunk) use (&$out): void {
                $out .= $chunk;
            },
        );

        $rows = [];
        foreach (array_filter(explode("\n", $out), static fn (string $l): bool => $l !== '') as $line) {
            $decoded = json_decode($line, true);
            $rows[] = is_array($decoded) ? $decoded : [];
        }

        return $rows;
    }

    public function test_an_export_in_one_named_sandbox_excludes_another_sandboxs_rows(): void
    {
        $this->seedInvoice('sbx-a', 'A-1');
        $this->seedInvoice('sbx-b', 'B-1');

        $rowsA = $this->ndjson('invoices', ExportQuery::plane('sbx-a', false));
        $this->assertSame(['A-1'], array_column($rowsA, 'number'));

        $rowsB = $this->ndjson('invoices', ExportQuery::plane('sbx-b', false));
        $this->assertSame(['B-1'], array_column($rowsB, 'number'));
    }

    public function test_a_dsar_in_one_named_sandbox_excludes_another_sandboxs_rows(): void
    {
        $this->seedInvoice('sbx-a', 'A-1');
        $this->seedInvoice('sbx-b', 'B-1');

        $numbers = $this->inEnvironment('sbx-a', function (): array {
            $organization = Organization::query()->findOrFail('org_shared');
            $bundle = app(AssemblesDsarBundle::class)->build($organization, false);

            $phar = new PharData($bundle->path);
            $invoices = $phar['invoices.ndjson']->getContent();
            @unlink($bundle->path);

            $out = [];
            foreach (array_filter(explode("\n", $invoices), static fn (string $l): bool => $l !== '') as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && isset($decoded['number'])) {
                    $out[] = $decoded['number'];
                }
            }

            return $out;
        });

        // The DSAR for org_shared in sbx-a contains ONLY sbx-a's invoice, never sbx-b's.
        $this->assertSame(['A-1'], $numbers);
    }
}
