<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Tax\Exemptions\ExemptionStatus;
use App\Billing\Tax\Exemptions\ExemptionType;
use App\Billing\Tax\TaxContextFactory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SellerEntity;
use App\Models\Subscription;
use App\Models\TaxExemptionCertificate;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;
use Cbox\Billing\Quote\ValueObjects\Quote;
use Cbox\Tax\Enums\TaxTreatment;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The core of the exemption feature: a verified, non-expired certificate covering the
 * transaction's jurisdiction zero-rates that jurisdiction's tax (recording the cert as an
 * audit trail), while every other jurisdiction — and every non-verified certificate — is
 * still taxed. Preview == charge, and EU VAT reverse-charge is untouched.
 */
class TaxExemptionApplicationTest extends TestCase
{
    use RefreshDatabase;

    /** Give the default selling entity US nexus in CA and NY so US sales tax actually applies. */
    private function usSellerWithCaAndNyNexus(): void
    {
        $seller = SellerEntity::query()->create([
            'id' => 'us-co', 'legal_name' => 'US Co', 'registration_number' => 'US-0001',
            'establishment' => 'US', 'currency' => 'USD', 'invoice_prefix' => 'USCO', 'is_default' => true,
        ]);

        $seller->taxRegistrations()->createMany([
            ['country' => 'US', 'number' => 'CA-PERMIT-1', 'subdivision' => 'US-CA'],
            ['country' => 'US', 'number' => 'NY-PERMIT-1', 'subdivision' => 'US-NY'],
        ]);
    }

    private function orgAt(string $id, ?string $subdivision): Organization
    {
        return Organization::query()->create([
            'id' => $id,
            'name' => strtoupper((string) $subdivision).' Buyer',
            'billing_email' => $id.'@example.test',
            'billing_country' => 'US',
            'billing_subdivision' => $subdivision,
            'billing_currency' => 'USD',
            'tax_id_validated' => false,
        ]);
    }

    /** Build a $100 quote for an org through the SAME seam preview and charge both use. */
    private function quoteFor(Organization $organization): Quote
    {
        $context = app(TaxContextFactory::class)->forOrganization($organization);

        return app(QuoteBuilder::class)->build(
            [new LineInput('Subscription', 1, Money::ofMinor(10_000, 'USD'))],
            $context,
        );
    }

    private function certificate(Organization $organization, string $jurisdiction, ExemptionStatus $status, ?Carbon $expiresAt = null): TaxExemptionCertificate
    {
        return TaxExemptionCertificate::query()->create([
            'organization_id' => $organization->id,
            'jurisdiction' => $jurisdiction,
            'exemption_type' => ExemptionType::Resale,
            'certificate_number' => 'RESALE-'.$jurisdiction.'-001',
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_a_verified_resale_certificate_zero_rates_the_covered_state(): void
    {
        $this->usSellerWithCaAndNyNexus();
        $organization = $this->orgAt('org_ca', 'US-CA');

        // No certificate yet: CA is taxable (7.25% state rate) — $100 → $7.25 tax.
        $taxed = $this->quoteFor($organization);
        $this->assertSame(725, $taxed->totals->tax->minor());
        $this->assertSame(TaxTreatment::Standard, $taxed->lines[0]->treatment);

        // A verified, non-expired resale certificate for CA: the CA tax is exempted (0), and
        // the line records the cert (type + number) as the audit reason.
        $cert = $this->certificate($organization, 'US-CA', ExemptionStatus::Verified);

        $exempt = $this->quoteFor($organization);
        $this->assertSame(0, $exempt->totals->tax->minor());
        $this->assertSame(10_000, $exempt->totals->net->minor());
        $this->assertSame(TaxTreatment::Exempt, $exempt->lines[0]->treatment);
        $this->assertStringContainsString($cert->certificate_number, $exempt->lines[0]->taxNote);
        $this->assertStringContainsString('resale', $exempt->lines[0]->taxNote);
    }

    public function test_a_certificate_only_exempts_its_own_jurisdiction(): void
    {
        $this->usSellerWithCaAndNyNexus();

        // This org is billed in NY but holds a verified certificate for CA only. NY is a
        // different jurisdiction, so it is STILL taxed (4% NY state rate) — a CA cert never
        // exempts NY.
        $organization = $this->orgAt('org_ny', 'US-NY');
        $this->certificate($organization, 'US-CA', ExemptionStatus::Verified);

        $quote = $this->quoteFor($organization);

        $this->assertSame(400, $quote->totals->tax->minor());
        $this->assertSame(TaxTreatment::Standard, $quote->lines[0]->treatment);
    }

    public function test_pending_rejected_and_expired_certificates_do_not_exempt(): void
    {
        $this->usSellerWithCaAndNyNexus();

        foreach ([
            'org_pending' => ExemptionStatus::Pending,
            'org_rejected' => ExemptionStatus::Rejected,
            'org_expired_status' => ExemptionStatus::Expired,
        ] as $id => $status) {
            $organization = $this->orgAt($id, 'US-CA');
            $this->certificate($organization, 'US-CA', $status);

            $quote = $this->quoteFor($organization);

            $this->assertSame(725, $quote->totals->tax->minor(), sprintf('%s must still be taxed', $status->value));
            $this->assertSame(TaxTreatment::Standard, $quote->lines[0]->treatment);
        }

        // A VERIFIED but past-expiry certificate also does not exempt (deny-by-default, even
        // before the expire command flips its stored status).
        $organization = $this->orgAt('org_past_expiry', 'US-CA');
        $this->certificate($organization, 'US-CA', ExemptionStatus::Verified, Carbon::now()->subDay());

        $quote = $this->quoteFor($organization);
        $this->assertSame(725, $quote->totals->tax->minor());
    }

    public function test_preview_equals_charge_with_the_exemption_recorded_on_the_invoice(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->usSellerWithCaAndNyNexus();

        $organization = $this->orgAt('org_charge', 'US-CA');
        $this->certificate($organization, 'US-CA', ExemptionStatus::Verified);

        $plan = Plan::query()->where('key', 'starter')->firstOrFail();
        app(SubscribesOrganizations::class)->subscribe($organization, $plan, seats: 1);
        $subscription = Subscription::query()->where('organization_id', $organization->id)->firstOrFail();

        // Preview (the quote) is tax-free…
        $preview = app(GeneratesInvoices::class)->quoteFor($subscription);
        $this->assertSame(0, $preview->totals->tax->minor());

        // …and the charged invoice matches exactly, with the exemption stamped as an audit trail.
        $invoice = app(GeneratesInvoices::class)->generate($subscription);
        $this->assertSame(0, $invoice->tax_minor);
        $this->assertSame($preview->totals->net->minor(), $invoice->subtotal_minor);
        $this->assertSame($preview->totals->gross->minor(), $invoice->total_minor);
        $this->assertTrue($invoice->isTaxExempt());
        $this->assertStringContainsString('RESALE-US-CA-001', (string) $invoice->exemption_reason);

        $line = $invoice->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame(TaxTreatment::Exempt->value, $line->tax_treatment);
    }

    public function test_eu_vat_reverse_charge_is_unaffected_by_a_certificate(): void
    {
        // Use the default config seller (established DK). A German business with a validated
        // VAT id buying cross-border self-accounts (reverse charge) — and a certificate must
        // NOT change that treatment (it is not a tax the seller collects).
        $organization = Organization::query()->create([
            'id' => 'org_de',
            'name' => 'DE GmbH',
            'billing_email' => 'de@example.test',
            'billing_country' => 'DE',
            'billing_currency' => 'EUR',
            'tax_id' => 'DE123456789',
            'tax_id_validated' => true,
        ]);

        $this->certificate($organization, 'DE', ExemptionStatus::Verified);

        $context = app(TaxContextFactory::class)->forOrganization($organization);
        $quote = app(QuoteBuilder::class)->build(
            [new LineInput('Subscription', 1, Money::ofMinor(10_000, 'EUR'))],
            $context,
        );

        $this->assertSame(TaxTreatment::ReverseCharge, $quote->lines[0]->treatment);
        $this->assertSame(0, $quote->totals->tax->minor());
    }
}
