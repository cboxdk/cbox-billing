<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Payments\Contracts\PaysInvoices;
use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VaultPaymentGateway;
use Tests\TestCase;

/**
 * A renewal charge must name the instrument it intends to pull.
 *
 * The regression: the renewal intent carried only an id, an amount and a reference. With no
 * gateway customer and no vaulted method, a real gateway creates an intent that is waiting for
 * a payment method — Stripe's `requires_payment_method` — which the status mapper reads as
 * "needs action", not "failed". `PaymentRetryService::chargeRenewal()` only opens dunning on a
 * FAILED result, so the subscription renewed, collected nothing, and raised nothing. The whole
 * suite stayed green because the default test gateway returns a settled result regardless of
 * what it is handed.
 *
 * So these tests assert on the INTENT the gateway received, not on the result it chose to
 * return. A gateway that is asked to charge nothing in particular is the bug.
 */
class OffSessionRenewalChargeTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(string $org): Invoice
    {
        Organization::query()->create(['id' => $org, 'name' => 'Renewal Co', 'billing_currency' => 'DKK']);

        return Invoice::query()->create([
            'organization_id' => $org,
            'number' => 'CBOX-DK-2026-00001',
            'currency' => 'DKK',
            'subtotal_minor' => 10000,
            'tax_minor' => 2500,
            'total_minor' => 12500,
            'seller' => 'cbox-dk',
            'status' => 'open',
            'issued_at' => now(),
        ]);
    }

    public function test_a_renewal_charges_the_accounts_default_vaulted_method(): void
    {
        $gateway = new VaultPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        $invoice = $this->invoiceFor('org_offsession');

        // Vault a card and make it the default, exactly as the portal's add-card flow does.
        // The account must come from the RESOLVER, which is what the charge path will use —
        // attaching to some other customer id would vault a card the renewal never sees.
        $account = $this->app->make(ResolvesGatewayCustomer::class)->resolve($invoice->organization);
        $gateway->attachPaymentMethod($account, 'pm_vaulted');
        $gateway->setDefaultPaymentMethod($account, 'pm_vaulted');

        $this->app->make(PaysInvoices::class)->pay($invoice->refresh());

        $intent = $gateway->charged[0] ?? null;

        $this->assertNotNull($intent, 'The gateway must have been asked to charge.');
        $this->assertTrue(
            $intent->isOffSession(),
            'A renewal intent must name both the gateway customer and the vaulted instrument.',
        );
        $this->assertSame('pm_vaulted', $intent->paymentMethodRef);
    }

    public function test_an_account_with_no_saved_method_fails_so_dunning_opens(): void
    {
        $gateway = new VaultPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        $invoice = $this->invoiceFor('org_nocard');

        $result = $this->app->make(PaysInvoices::class)->pay($invoice->refresh());

        // Failing is the recoverable outcome: PaymentRetryService opens dunning on Failed, which
        // emails the customer to add a card. Reporting anything softer strands the account
        // serving for free with nobody alerted.
        $this->assertSame(PaymentStatus::Failed, $result->status);
    }
}
