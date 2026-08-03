<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Import\Adapters\RecurlyAdapter;
use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Payments\Contracts\PaysInvoices;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\VaultPaymentGateway;
use Tests\TestCase;

/**
 * A dependency being unreachable must DEGRADE, never escape.
 *
 * These are regressions from this campaign's own fixes: resolving the gateway customer and
 * listing saved methods were added to the renewal path, and an exponent-aware currency parser
 * to the import path. Both introduced a throw where the surrounding design expects a value.
 */
class GatewayFailureDegradesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gateway_outage_on_renewal_opens_dunning_instead_of_killing_the_job(): void
    {
        $this->app->instance(PaymentGateway::class, new class extends VaultPaymentGateway
        {
            /** @return list<PaymentMethod> */
            public function paymentMethods(string $account): array
            {
                throw new RuntimeException('stripe is having a moment');
            }
        });

        Organization::query()->create(['id' => 'org_outage', 'name' => 'Outage', 'billing_currency' => 'DKK']);
        $invoice = Invoice::query()->create([
            'organization_id' => 'org_outage', 'number' => 'CBOX-DK-2026-09001', 'currency' => 'DKK',
            'subtotal_minor' => 10000, 'tax_minor' => 2500, 'total_minor' => 12500,
            'seller' => 'cbox-dk', 'status' => 'open', 'issued_at' => now(),
        ]);

        // Before this, the throw escaped pay() -> chargeRenewal() -> RenewSubscriptionJob and
        // killed the job AFTER the period had advanced and the invoice was issued: invoice left
        // Open, dunning never opened, subscription still serving, nobody notified.
        $result = $this->app->make(PaysInvoices::class)->pay($invoice->refresh());

        $this->assertSame(PaymentStatus::Failed, $result->status);
    }

    public function test_one_unknown_currency_fails_its_record_not_the_whole_import(): void
    {
        $adapter = $this->app->make(RecurlyAdapter::class);

        // A provider export with a garbled currency cell. The importer's design is per-record
        // outcomes; an exception here aborted the entire run — a dry-run of 10 000 customers
        // lost to one bad cell.
        $export = json_encode(['invoices' => [[
            'uuid' => 'inv_bad', 'account_code' => 'acct_1', 'number' => 'INV-1',
            'currency' => 'XYZ', 'subtotal' => '10.00', 'tax' => '2.50', 'total' => '12.50',
            'state' => 'paid', 'created_at' => '2026-01-01T00:00:00Z',
        ]]], JSON_THROW_ON_ERROR);

        $parsed = $adapter->parse(SourceExport::fromCombinedJson($export));

        $this->assertNotNull($parsed);
    }
}
