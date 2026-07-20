<?php

declare(strict_types=1);

namespace App\Billing\Audit\Dsar;

use App\Billing\Audit\Contracts\AssemblesDsarBundle;
use App\Billing\Audit\ValueObjects\DsarBundle;
use App\Billing\Export\DataExporter;
use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Encoders\RowEncoderFactory;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Mode\BillingContext;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use PharData;

/**
 * Assembles the DSAR (data-subject access) bundle by REUSING the export system: for each
 * subject-scopable dataset it pumps the organization's rows through the NDJSON encoder into one
 * file per dataset, then packs those files plus a `manifest.json` into a single `.tar.gz`
 * archive (built with PHP's bundled Phar — no extra extension or dependency).
 *
 * The dataset set is an explicit allow-list (deny-by-default): only datasets known to honour
 * subject scoping are included, so a computed/aggregate view can never leak another subject's
 * rows into a bundle. The manifest states the redact-vs-retain policy plainly, so the recipient
 * understands the bundle reflects retained financial records too.
 */
readonly class DsarBundleBuilder implements AssemblesDsarBundle
{
    /**
     * The datasets a DSAR bundle draws from, in file order. Every one is subject-scopable
     * (a direct org column or a parent sub-select); a computed view is deliberately excluded.
     *
     * @var list<string>
     */
    private const DATASETS = [
        'customers', 'subscriptions', 'invoices', 'invoice_lines', 'credit_notes',
        'payments', 'mrr_movements', 'coupon_redemptions', 'seat_assignments',
        'licenses', 'usage_events', 'dunning', 'audit_events',
    ];

    public function __construct(
        private DatasetRegistry $registry,
        private DataExporter $exporter,
        private RowEncoderFactory $encoders,
        private BillingContext $context,
    ) {}

    public function build(Organization $organization, bool $livemode): DsarBundle
    {
        $encoder = $this->encoders->for(ExportFormat::Ndjson);
        // Partition by the CURRENT named plane (not the binary livemode), so a DSAR built in one
        // named sandbox never spans another sandbox's rows for the same org id. `$livemode` remains
        // the byte-stable plane label the manifest and filename carry.
        $query = ExportQuery::forOrganization($this->context->environmentKey(), $livemode, $organization->id);

        $workDir = $this->workDir($organization->id);
        $counts = [];

        foreach (self::DATASETS as $key) {
            if (! $this->registry->has($key)) {
                continue;
            }

            $buffer = '';
            $result = $this->exporter->pump($this->registry->get($key), $encoder, $query, function (string $chunk) use (&$buffer): void {
                $buffer .= $chunk;
            });

            // Header-only (zero data rows) datasets are omitted, so the bundle carries only the
            // subject's actual records.
            if ($result->rows > 0) {
                file_put_contents($workDir.'/'.$key.'.ndjson', $buffer);
                $counts[$key] = $result->rows;
            }
        }

        $manifest = $this->manifest($organization, $livemode, $counts);
        file_put_contents($workDir.'/manifest.json', $manifest);

        $archivePath = $this->pack($workDir, $organization->id, $livemode);
        $this->cleanup($workDir);

        return new DsarBundle(
            path: $archivePath,
            filename: basename($archivePath),
            organizationId: $organization->id,
            livemode: $livemode,
            datasetCounts: $counts,
        );
    }

    /**
     * The bundle manifest — what the subject is, when it was generated, the plane, the per-dataset
     * counts, and the honest redact-vs-retain note.
     *
     * @param  array<string, int>  $counts
     */
    private function manifest(Organization $organization, bool $livemode, array $counts): string
    {
        return json_encode([
            'kind' => 'cbox-billing.dsar-bundle',
            'version' => 1,
            'subject' => [
                'organization_id' => $organization->id,
                'name' => $organization->name,
                'billing_email' => $organization->billing_email,
                'erased' => $organization->isErased(),
            ],
            'plane' => $livemode ? 'live' : 'test',
            'generated_at' => Carbon::now()->toIso8601String(),
            'datasets' => $counts,
            'total_rows' => array_sum($counts),
            'notes' => [
                'This bundle is the data we hold about the subject organization on the '
                    .($livemode ? 'live' : 'test').' plane, one newline-delimited JSON file per dataset.',
                'Financial documents (invoices, credit notes, payments, ledger) are RETAINED under '
                    .'statutory retention even after an erasure request; an erased subject appears here '
                    .'with PII tombstones but with its financial records intact and de-identified.',
                'The audit_events file carries hash-chain columns (sequence, prev_hash, hash) so the '
                    .'included operator-action trail can be verified independently.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** Pack the working directory into a gzipped tar and return its path. */
    private function pack(string $workDir, string $organizationId, bool $livemode): string
    {
        $base = sprintf(
            'dsar_%s_%s_%s_%s',
            $this->slug($organizationId),
            $livemode ? 'live' : 'test',
            Carbon::now()->format('Ymd_His'),
            bin2hex(random_bytes(4)),
        );

        $tarPath = storage_path('app/dsar/'.$base.'.tar');
        @unlink($tarPath);
        @unlink($tarPath.'.gz');

        $tar = new PharData($tarPath);
        $tar->buildFromDirectory($workDir);
        $tar->compress(\Phar::GZ);

        // compress() writes a sibling .tar.gz; drop the intermediate uncompressed .tar.
        @unlink($tarPath);

        return $tarPath.'.gz';
    }

    private function workDir(string $organizationId): string
    {
        $dir = storage_path('app/dsar/work_'.$this->slug($organizationId).'_'.bin2hex(random_bytes(6)));

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    private function cleanup(string $workDir): void
    {
        foreach (glob($workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($workDir);
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? 'subject';

        return trim($slug, '-') !== '' ? trim($slug, '-') : 'subject';
    }
}
