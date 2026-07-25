<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Tax\Contracts\VerifiesCustomerTaxIds;
use App\Billing\Tax\Enums\CustomerKind;
use App\Billing\Tax\Enums\TaxIdVerdict;
use App\Billing\Tax\RegisterTaxIdVerifier;
use App\Billing\Tax\TaxContextFactory;
use App\Billing\Tax\Testing\FakeTaxIdVerifier;
use App\Models\Organization;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\Enums\CustomerType;
use Cbox\Tax\ValueObjects\VatIdValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EU B2B reverse charge was unreachable. The engine's branch requires
 * `customerTaxIdValidated === true`; `organizations.tax_id_validated` defaulted to false and NO
 * production path ever set it — only test fixtures did. The console collected a VAT number and
 * nothing checked it, while the engine's working VIES validator sat bound and unused.
 *
 * Consequence: a Danish seller invoicing a German GmbH with a valid DE VAT ID charged German VAT
 * it has no German registration to remit. A real liability, and a product unusable for EU B2B.
 *
 * These tests cover the whole path — the verifier's four verdicts, the persisted evidence, the
 * customer-type derivation that had to ship with it, and the console save that triggers it.
 */
class ReverseChargeReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $id, string $country = 'DE', ?string $taxId = 'DE123456789', string $type = 'business'): Organization
    {
        return Organization::query()->create([
            'id' => $id,
            'name' => 'Kunde '.$id,
            'billing_email' => $id.'@example.test',
            'billing_country' => $country,
            'tax_id' => $taxId,
            'customer_type' => $type,
        ]);
    }

    /** Bind a scripted register answer so no test reaches the network. */
    private function register(VatIdValidation $answer, bool $supports = true): void
    {
        $this->app->bind(VatIdValidator::class, fn (): VatIdValidator => new class($answer, $supports) implements VatIdValidator
        {
            public function __construct(private VatIdValidation $answer, private bool $supports) {}

            public function supports(CountryCode $country): bool
            {
                return $this->supports;
            }

            public function validate(CountryCode $country, string $taxId): VatIdValidation
            {
                return $this->answer;
            }
        });
    }

    // ---- the verifier's verdicts -------------------------------------------------------------

    public function test_a_valid_vat_id_validates_the_org_and_stores_the_evidence(): void
    {
        $this->register(VatIdValidation::valid('vies', name: 'Kunde GmbH', consultationReference: 'WAPIAAAABBBB'));

        $org = $this->org('org_valid');
        $result = $this->app->make(RegisterTaxIdVerifier::class)->verify($org);

        $this->assertSame(TaxIdVerdict::Valid, $result->verdict);
        $this->assertTrue($result->isValidated());

        $org->refresh();
        $this->assertTrue($org->tax_id_validated);
        $this->assertNotNull($org->tax_id_validated_at);
        // The consultation reference is the evidence a tax authority asks for when a supply was
        // zero-rated — storing only the boolean keeps the outcome and discards the proof.
        $this->assertSame('WAPIAAAABBBB', $org->tax_id_validation_reference);
        $this->assertSame('vies', $org->tax_id_validation_source);
    }

    public function test_an_invalid_vat_id_leaves_the_org_unvalidated(): void
    {
        $this->register(VatIdValidation::invalid('vies'));

        $org = $this->org('org_invalid');
        $result = $this->app->make(RegisterTaxIdVerifier::class)->verify($org);

        $this->assertSame(TaxIdVerdict::Invalid, $result->verdict);
        $this->assertFalse($org->refresh()->tax_id_validated);
        $this->assertNull($org->tax_id_validation_reference);
    }

    /**
     * The branch with money attached: a register OUTAGE must not quietly grant reverse charge.
     * Over-charging is visible and refundable; wrongly zero-rating leaves the seller owing tax it
     * never collected.
     */
    public function test_an_unreachable_register_fails_toward_charging_vat(): void
    {
        $this->register(VatIdValidation::inconclusive('vies'));

        $org = $this->org('org_outage');
        $result = $this->app->make(RegisterTaxIdVerifier::class)->verify($org);

        $this->assertSame(TaxIdVerdict::Inconclusive, $result->verdict);
        $this->assertTrue($result->verdict->isRetryable());
        $this->assertFalse($org->refresh()->tax_id_validated);
    }

    /** A validator that throws is an outage, not a verdict — same safe direction. */
    public function test_a_throwing_validator_is_treated_as_an_outage(): void
    {
        $this->app->bind(VatIdValidator::class, fn (): VatIdValidator => new class implements VatIdValidator
        {
            public function supports(CountryCode $country): bool
            {
                return true;
            }

            public function validate(CountryCode $country, string $taxId): VatIdValidation
            {
                throw new \RuntimeException('VIES timed out');
            }
        });

        $org = $this->org('org_throws');
        $result = $this->app->make(RegisterTaxIdVerifier::class)->verify($org);

        $this->assertSame(TaxIdVerdict::Inconclusive, $result->verdict);
        $this->assertFalse($org->refresh()->tax_id_validated);
    }

    public function test_a_country_with_no_register_is_unsupported_not_invalid(): void
    {
        $this->register(VatIdValidation::invalid('vies'), supports: false);

        $result = $this->app->make(RegisterTaxIdVerifier::class)->verify($this->org('org_us', 'US', '12-3456789'));

        $this->assertSame(TaxIdVerdict::Unsupported, $result->verdict);
    }

    public function test_an_org_with_no_tax_id_reports_not_provided(): void
    {
        $result = $this->app->make(RegisterTaxIdVerifier::class)->verify($this->org('org_none', 'DE', null));

        $this->assertSame(TaxIdVerdict::NotProvided, $result->verdict);
    }

    /**
     * Evidence must never outlive the validation it evidenced. A VAT ID valid last year may be
     * deregistered today, and a stale consultation reference is worse than none.
     */
    public function test_a_later_failure_clears_the_previous_evidence(): void
    {
        $this->register(VatIdValidation::valid('vies', consultationReference: 'OLD_REF'));
        $org = $this->org('org_rotate');
        $this->app->make(RegisterTaxIdVerifier::class)->verify($org);
        $this->assertSame('OLD_REF', $org->refresh()->tax_id_validation_reference);

        $this->register(VatIdValidation::invalid('vies'));
        $this->app->make(RegisterTaxIdVerifier::class)->verify($org);

        $org->refresh();
        $this->assertFalse($org->tax_id_validated);
        $this->assertNull($org->tax_id_validation_reference);
        $this->assertNull($org->tax_id_validated_at);
    }

    // ---- the tax context -----------------------------------------------------------------------

    public function test_a_validated_business_reaches_the_reverse_charge_gate(): void
    {
        $this->register(VatIdValidation::valid('vies', consultationReference: 'REF'));
        $org = $this->org('org_b2b');
        $this->app->make(RegisterTaxIdVerifier::class)->verify($org);

        $context = $this->app->make(TaxContextFactory::class)->forOrganization($org->refresh());

        $this->assertTrue($context->customerTaxIdValidated, 'A verified business must reach the reverse-charge branch.');
        $this->assertSame(CustomerType::Business, $context->customer);
    }

    /**
     * The other half of the fix. The context hardcoded Business for EVERY buyer, which was
     * invisible only while the gate could not open — opening it without this would zero-rate
     * genuine consumers on any cross-border supply.
     */
    public function test_a_consumer_is_never_treated_as_a_business(): void
    {
        $org = $this->org('org_b2c', 'DE', null, CustomerKind::Consumer->value);

        $context = $this->app->make(TaxContextFactory::class)->forOrganization($org);

        $this->assertSame(CustomerType::Consumer, $context->customer);
        $this->assertFalse($context->customerTaxIdValidated);
    }

    /** An org predating the column keeps the behaviour it had — business. */
    public function test_an_unset_customer_type_defaults_to_business(): void
    {
        $this->assertSame(CustomerKind::Business, CustomerKind::fromStorage(null));
        $this->assertSame(CustomerKind::Business, CustomerKind::fromStorage('nonsense'));
    }

    // ---- the console save ----------------------------------------------------------------------

    public function test_changing_the_tax_id_triggers_verification(): void
    {
        $fake = new FakeTaxIdVerifier;
        $this->app->instance(VerifiesCustomerTaxIds::class, $fake);

        config()->set('billing.console.operator_orgs', ['org_ops']);
        config()->set('billing.console.operator_subjects', []);

        $org = $this->org('org_save', 'DE', null);

        $this->withSession(['auth.user' => [
            'sub' => 'demo|user', 'name' => 'Op', 'email' => 'op@example.test', 'org' => 'org_ops', 'picture' => null,
        ]])->put("/customers/{$org->id}", [
            'name' => 'Kunde GmbH',
            'tax_id' => 'DE123456789',
            'customer_type' => 'business',
        ])->assertRedirect();

        $this->assertSame(['org_save'], $fake->verified, 'Saving a new tax ID must verify it.');
        $this->assertTrue($org->refresh()->tax_id_validated);
    }

    /** An unchanged tax ID must not re-hit the register on every unrelated profile edit. */
    public function test_saving_without_changing_the_tax_id_does_not_re_verify(): void
    {
        $fake = new FakeTaxIdVerifier;
        $this->app->instance(VerifiesCustomerTaxIds::class, $fake);

        config()->set('billing.console.operator_orgs', ['org_ops']);
        config()->set('billing.console.operator_subjects', []);

        $org = $this->org('org_same', 'DE', 'DE123456789');

        $this->withSession(['auth.user' => [
            'sub' => 'demo|user', 'name' => 'Op', 'email' => 'op@example.test', 'org' => 'org_ops', 'picture' => null,
        ]])->put("/customers/{$org->id}", [
            'name' => 'Renamed GmbH',
            'tax_id' => 'DE123456789',
            'customer_type' => 'business',
        ])->assertRedirect();

        $this->assertSame([], $fake->verified);
    }
}
