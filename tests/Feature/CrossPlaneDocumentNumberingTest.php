<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Mode\BillingContext;
use App\Billing\Seller\SellerCatalog;
use App\Models\Environment;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SellerEntity;
use Cbox\Billing\Invoice\Contracts\CreditNoteNumberSequence;
use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Database\Seeders\EnvironmentSeeder;
use Database\Seeders\SellerEntitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1 ROOT CAUSE — TWO PLANES MINTING THE SAME LEGAL DOCUMENT NUMBER.
 *
 * An invoice number is `<PREFIX>-<YEAR>-<0000N>` and its counter is per SELLER. Cloning an
 * environment gave the cloned seller a plane-namespaced primary key (so it draws its OWN counter,
 * from 1) but copied the invoice PREFIX verbatim — so production and the clone both legitimately
 * issued `CBOX-DK-2026-00001` while still satisfying the `(seller, number)` unique index. That
 * byte-identical number is what let an ambiguous, reference-only settlement payload address two
 * planes at once (and, resolving to the ambient plane, settle PRODUCTION's invoice).
 *
 * The collision is now closed at the source: a non-production plane numbers under a plane-distinct
 * prefix — for a cloned seller and for the `billing.seller` CONFIG fallback alike — and the counters
 * behind those numbers are keyed by `(seller, environment)` so no sandbox draw can advance, or gap,
 * production's legal series.
 */
class CrossPlaneDocumentNumberingTest extends TestCase
{
    use RefreshDatabase;

    /** A sandbox CLONED from production (copies the seller register). */
    private const CLONE = 'ci-clone';

    /** A sandbox created WITHOUT a clone — it has no seller row, so it resolves the config seller. */
    private const SOLO = 'ci-solo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);
        $this->seed(SellerEntitySeeder::class);
    }

    /** @template T @param callable(): T $work @return T */
    private function inPlane(string $key, callable $work): mixed
    {
        return app(BillingContext::class)->runInEnvironment(
            Environment::query()->where('key', $key)->firstOrFail(),
            $work,
        );
    }

    private function production(): Environment
    {
        return Environment::query()->where('key', Environment::PRODUCTION)->firstOrFail();
    }

    /** The next legal INVOICE number the plane's default seller issues. */
    private function nextInvoiceNumber(string $plane): string
    {
        return $this->inPlane($plane, static fn (): string => app(InvoiceNumberSequence::class)->next(
            app(SellerCatalog::class)->default(),
        ));
    }

    /** The next legal CREDIT NOTE number the plane's default seller issues. */
    private function nextCreditNoteNumber(string $plane): string
    {
        return $this->inPlane($plane, static fn (): string => app(CreditNoteNumberSequence::class)->next(
            app(SellerCatalog::class)->default(),
        ));
    }

    /** Persist an invoice under the plane's default seller — proves `(seller, number)` still holds. */
    private function issueInvoice(string $plane, string $number): Invoice
    {
        return $this->inPlane($plane, function () use ($plane, $number): Invoice {
            $org = 'org_'.$plane;

            Organization::query()->firstOrCreate(['id' => $org], ['name' => $org, 'billing_country' => 'DK']);

            return Invoice::query()->create([
                'organization_id' => $org,
                'seller' => app(SellerCatalog::class)->default()->id,
                'number' => $number,
                'currency' => 'EUR',
                'subtotal_minor' => 10_000, 'tax_minor' => 0, 'total_minor' => 10_000,
                'status' => InvoiceStatus::Open, 'issued_at' => now(), 'due_at' => now()->addDays(14),
            ]);
        });
    }

    /**
     * THE ROOT FIX. A cloned seller keeps its identity but not its numbering: the clone's prefix
     * carries the plane's own key, so the two planes can never mint the same number — while each
     * still numbers gaplessly from 1 under its own counter.
     */
    public function test_a_cloned_seller_numbers_under_a_plane_distinct_prefix(): void
    {
        app(CreatesEnvironments::class)->create(key: self::CLONE, cloneFrom: $this->production());

        $source = SellerEntity::query()->withoutGlobalScopes()
            ->where('environment', Environment::PRODUCTION)->firstOrFail();
        $clone = SellerEntity::query()->withoutGlobalScopes()
            ->where('environment', self::CLONE)->firstOrFail();

        // Same legal identity, plane-namespaced id, DIFFERENT document prefix.
        $this->assertSame($source->legal_name, $clone->legal_name);
        $this->assertSame(self::CLONE.'__'.$source->id, $clone->id);
        $this->assertNotSame($source->invoice_prefix, $clone->invoice_prefix);
        $this->assertSame($source->invoice_prefix.'-CI-CLONE', $clone->invoice_prefix);

        // Issuing in each plane yields DIFFERENT numbers — this is the assertion that fails
        // pre-fix, where both planes minted the byte-identical `<PREFIX>-<YEAR>-00001`.
        $live = $this->nextInvoiceNumber(Environment::PRODUCTION);
        $sandbox = $this->nextInvoiceNumber(self::CLONE);

        $this->assertNotSame($live, $sandbox);
        $this->assertSame($source->invoice_prefix.'-'.date('Y').'-00001', $live);
        $this->assertSame($clone->invoice_prefix.'-'.date('Y').'-00001', $sandbox);

        // Both series stay gapless per seller, independently.
        $this->assertSame($source->invoice_prefix.'-'.date('Y').'-00002', $this->nextInvoiceNumber(Environment::PRODUCTION));
        $this->assertSame($clone->invoice_prefix.'-'.date('Y').'-00002', $this->nextInvoiceNumber(self::CLONE));
        $this->assertSame($source->invoice_prefix.'-'.date('Y').'-00003', $this->nextInvoiceNumber(Environment::PRODUCTION));

        // And the `(seller, number)` uniqueness holds in both planes: the numbers are distinct, and
        // so are the sellers issuing them.
        $this->issueInvoice(Environment::PRODUCTION, $live);
        $this->issueInvoice(self::CLONE, $sandbox);

        $this->assertSame(1, Invoice::query()->withoutGlobalScopes()->where('number', $live)->count());
        $this->assertSame(1, Invoice::query()->withoutGlobalScopes()->where('number', $sandbox)->count());
    }

    /** Credit notes ride the same prefix, so their legal series is plane-distinct too. */
    public function test_a_cloned_seller_credit_notes_are_plane_distinct(): void
    {
        app(CreatesEnvironments::class)->create(key: self::CLONE, cloneFrom: $this->production());

        $live = $this->nextCreditNoteNumber(Environment::PRODUCTION);
        $sandbox = $this->nextCreditNoteNumber(self::CLONE);

        $this->assertNotSame($live, $sandbox);
        $this->assertStringContainsString('-CN-', $live);
        $this->assertStringContainsString('-CI-CLONE-CN-', $sandbox);
    }

    /**
     * THE SEQUENCE GUARD. A plane with no authored seller row falls back to the `billing.seller`
     * CONFIG — the SAME seller id in every plane. Keying the counter by `(seller, environment)` is
     * what stops that sandbox from drawing production's counter: pre-fix the sandbox consumed
     * production's next number, so production's legal series skipped one (…00001, then …00003).
     */
    public function test_a_sandbox_sharing_a_seller_id_never_advances_productions_sequence(): void
    {
        app(CreatesEnvironments::class)->create(key: self::SOLO);

        $sellerId = $this->inPlane(self::SOLO, static fn (): string => app(SellerCatalog::class)->default()->id);

        // The config fallback really does resolve production's seller id in the sandbox — the exact
        // condition the guard exists for.
        $this->assertSame(
            $this->inPlane(Environment::PRODUCTION, static fn (): string => app(SellerCatalog::class)->default()->id),
            $sellerId,
        );

        $first = $this->nextInvoiceNumber(Environment::PRODUCTION);
        $this->nextInvoiceNumber(self::SOLO);
        $this->nextInvoiceNumber(self::SOLO);
        $second = $this->nextInvoiceNumber(Environment::PRODUCTION);

        // Production's numbering is untouched by the sandbox's two draws: gapless, 1 then 2.
        $this->assertSame('-'.date('Y').'-00001', substr($first, -11));
        $this->assertSame('-'.date('Y').'-00002', substr($second, -11));

        // Separate counter rows, one per plane.
        $this->assertSame(2, DB::table('invoice_sequences')->where('seller', $sellerId)->count());
        $this->assertSame(3, (int) DB::table('invoice_sequences')
            ->where('seller', $sellerId)->where('environment', Environment::PRODUCTION)->value('next_value'));
        $this->assertSame(3, (int) DB::table('invoice_sequences')
            ->where('seller', $sellerId)->where('environment', self::SOLO)->value('next_value'));

        // …and even sharing a seller id, the two planes' numbers differ, because the config
        // fallback is plane-marked as well.
        $this->assertNotSame($first, $this->nextInvoiceNumber(self::SOLO));
    }
}
