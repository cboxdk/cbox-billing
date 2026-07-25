<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Invoicing\SellerDocumentIdentity;
use App\Billing\Mode\BillingContext;
use App\Billing\Seller\SellerCatalog;
use App\Models\CreditNote;
use App\Models\Environment;
use App\Models\Invoice;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Verify that every selling entity referenced by an already-issued legal document still resolves.
 *
 * Rendering an invoice PDF used to fall back to the raw seller KEY when the entity was configured
 * nowhere, producing a document with no legal name, no registration number and no VAT line — an
 * invalid VAT invoice under EU Directive 2006/112/EC Art. 226, issued silently. That fallback is
 * gone: rendering now refuses outright.
 *
 * The refusal is right for correctness, but it changes the failure mode for HISTORICAL rows: a
 * document whose seller key was removed from the register and the config since it was issued now
 * errors on download instead of rendering a (wrong) PDF. This command finds those rows BEFORE a
 * deploy does, so the gap is closed by re-registering the entity rather than discovered by a
 * customer clicking Download.
 *
 * Run it across every plane as part of the release checklist; a non-zero exit means at least one
 * issued document cannot currently be rendered.
 */
class VerifySellerIdentities extends Command
{
    protected $signature = 'billing:verify-seller-identities {--environment=* : Limit to these environment keys (default: all)}';

    protected $description = 'Check that every seller referenced by an issued invoice or credit note still resolves to a legal identity';

    public function handle(SellerCatalog $sellers, BillingContext $context): int
    {
        $planes = $this->planes();
        $unresolvable = [];

        foreach ($planes as $plane) {
            $context->runInEnvironment($plane, function () use ($sellers, $plane, &$unresolvable): void {
                foreach ($this->sellerKeysInPlane() as $key) {
                    try {
                        SellerDocumentIdentity::resolve($sellers, $key);
                    } catch (RuntimeException $e) {
                        $unresolvable[] = [$plane->key, $key, $e->getMessage()];
                    }
                }
            });
        }

        if ($unresolvable === []) {
            $this->info(sprintf(
                'All sellers referenced by issued documents resolve, across %d %s.',
                count($planes),
                count($planes) === 1 ? 'plane' : 'planes',
            ));

            return self::SUCCESS;
        }

        $this->error('Issued documents reference selling entities that no longer resolve:');
        $this->newLine();

        $this->table(
            ['Plane', 'Seller key', 'Why'],
            array_map(static fn (array $row): array => [$row[0], $row[1], str($row[2])->limit(80)->toString()], $unresolvable),
        );

        $this->newLine();
        $this->line('Downloading a PDF for any of these documents will fail. Re-register the entity');
        $this->line('under Settings → Seller entities (or restore it in `billing.seller.entities`)');
        $this->line('before deploying.');

        return self::FAILURE;
    }

    /**
     * The planes to check — the named ones, or every registered environment.
     *
     * @return list<Environment>
     */
    private function planes(): array
    {
        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $this->option('environment'), 'is_string'));

        $all = Environment::query()->withoutGlobalScopes()->get()->all();

        if ($all === []) {
            $all = [Environment::defaultProduction()];
        }

        if ($requested === []) {
            return array_values($all);
        }

        return array_values(array_filter($all, static fn (Environment $e): bool => in_array($e->key, $requested, true)));
    }

    /**
     * Every distinct seller key referenced by an issued document in the current plane. Both
     * document types are checked: a credit note can outlive the invoice it reverses.
     *
     * @return list<string>
     */
    private function sellerKeysInPlane(): array
    {
        $keys = Invoice::query()->distinct()->pluck('seller')
            ->merge(CreditNote::query()->distinct()->pluck('seller'))
            ->filter(static fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values();

        /** @var list<string> $result */
        $result = $keys->all();

        return $result;
    }
}
