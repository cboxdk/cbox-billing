<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Licensing\Contracts\LicenseRevocationRegistry;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Cbox\Billing\Payment\Contracts\ProcessedEventStore;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningState;
use Cbox\Billing\Refund\Contracts\RefundRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Re-review remediation: the per-org OPERATIONAL state tables (account standings, dunning states,
 * allowance leases, refunds, the two webhook dedup guards, and license revocations) must confine
 * every read/write to the request's plane. Each store carries `livemode` and scopes on it, so the
 * SAME org id (and the same reference) carries independent operational state per plane — a test
 * action can never read or affect the live plane, in BOTH directions.
 */
class CrossPlaneOperationalIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function context(): BillingContext
    {
        return app(BillingContext::class);
    }

    private function inTest(callable $fn): mixed
    {
        return $this->context()->runInMode(BillingMode::Test, $fn);
    }

    private function inLive(callable $fn): mixed
    {
        return $this->context()->runInMode(BillingMode::Live, $fn);
    }

    public function test_account_standing_is_confined_to_its_plane_in_both_directions(): void
    {
        $standing = app(AccountStanding::class);

        // TEST plane flags the org disputed.
        $this->inTest(fn () => $standing->flag('org_shared', AccountStandingState::Disputed, 'test chargeback'));

        // LIVE never sees it (deny-by-default → Good), and vice-versa once live flags Suspended.
        $this->assertSame(AccountStandingState::Good, $this->inLive(fn () => $standing->standingOf('org_shared')));
        $this->inLive(fn () => $standing->flag('org_shared', AccountStandingState::Suspended, 'live chargeback'));

        $this->assertSame(AccountStandingState::Disputed, $this->inTest(fn () => $standing->standingOf('org_shared')));
        $this->assertSame(AccountStandingState::Suspended, $this->inLive(fn () => $standing->standingOf('org_shared')));

        // Both rows genuinely coexist for the same org id.
        $this->assertSame(2, DB::table('account_standings')->where('account', 'org_shared')->count());
    }

    public function test_dunning_state_is_confined_to_its_plane_in_both_directions(): void
    {
        $store = app(DunningStateStore::class);

        $this->inTest(fn () => $store->save('org_dun', new DunningState(3, now()->toDateTimeImmutable())));

        // Live is a fresh slate; test carries the 3 notices.
        $this->assertSame(0, $this->inLive(fn () => $store->load('org_dun'))->noticesSent);
        $this->assertSame(3, $this->inTest(fn () => $store->load('org_dun'))->noticesSent);

        $this->inLive(fn () => $store->save('org_dun', new DunningState(1, now()->toDateTimeImmutable())));
        $this->assertSame(3, $this->inTest(fn () => $store->load('org_dun'))->noticesSent);
        $this->assertSame(1, $this->inLive(fn () => $store->load('org_dun'))->noticesSent);
    }

    public function test_allowance_leases_are_confined_to_their_plane_in_both_directions(): void
    {
        $source = app(AllowanceLeaseSource::class);

        // Seed outstanding leases for the same (org, meter) in each plane, then read back per plane.
        DB::table('allowance_leases')->insert([
            ['org' => 'org_lease', 'meter' => 'api_calls', 'livemode' => true, 'environment' => 'production', 'outstanding' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['org' => 'org_lease', 'meter' => 'api_calls', 'livemode' => false, 'environment' => 'sandbox', 'outstanding' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(40, $this->inLive(fn () => $source->outstandingFor('org_lease', 'api_calls')));
        $this->assertSame(5, $this->inTest(fn () => $source->outstandingFor('org_lease', 'api_calls')));

        // A give-back in TEST only touches the test row.
        $this->inTest(fn () => $source->giveBack('org_lease', 'api_calls', 5));
        $this->assertSame(0, $this->inTest(fn () => $source->outstandingFor('org_lease', 'api_calls')));
        $this->assertSame(40, $this->inLive(fn () => $source->outstandingFor('org_lease', 'api_calls')));
    }

    public function test_refunded_gross_cap_is_confined_to_its_plane(): void
    {
        $repo = app(RefundRepository::class);

        // Two refund rows against the same invoice_number+currency, one per plane.
        DB::table('refunds')->insert([
            $this->refundRow('rf_live', 'INV-1', 'live', true, 10_000),
            $this->refundRow('rf_test', 'INV-1', 'test', false, 300),
        ]);

        $this->assertSame(10_000, $this->inLive(fn () => $repo->refundedGross('INV-1', 'DKK'))->minor());
        $this->assertSame(300, $this->inTest(fn () => $repo->refundedGross('INV-1', 'DKK'))->minor());
    }

    public function test_settle_once_and_processed_guards_are_confined_to_their_plane(): void
    {
        $settled = app(SettledPaymentStore::class);
        $processed = app(ProcessedEventStore::class);

        // A TEST settlement of a reference does not settle the same reference in LIVE.
        $this->inTest(fn () => $settled->settle('REF-1'));
        $this->assertTrue($this->inTest(fn () => $settled->isSettled('REF-1')));
        $this->assertFalse($this->inLive(fn () => $settled->isSettled('REF-1')));

        // A TEST-seen event id can still be remembered (first-sight) in LIVE.
        $this->assertTrue($this->inTest(fn () => $processed->remember('evt-1')));
        $this->assertFalse($this->inTest(fn () => $processed->remember('evt-1')));
        $this->assertTrue($this->inLive(fn () => $processed->remember('evt-1')));
    }

    public function test_license_revocations_are_confined_to_their_plane_in_both_directions(): void
    {
        $registry = app(LicenseRevocationRegistry::class);

        $this->inTest(fn () => $registry->revoke('lic_x', 'test revoke'));

        $this->assertTrue($this->inTest(fn () => $registry->isRevoked('lic_x')));
        $this->assertFalse($this->inLive(fn () => $registry->isRevoked('lic_x')));
        $this->assertSame([], $this->inLive(fn () => $registry->revokedIds()));
        $this->assertSame(['lic_x'], $this->inTest(fn () => $registry->revokedIds()));
    }

    /**
     * @return array<string, mixed>
     */
    private function refundRow(string $id, string $invoice, string $account, bool $livemode, int $gross): array
    {
        return [
            'refund_id' => $id,
            'livemode' => $livemode,
            'environment' => $livemode ? 'production' : 'sandbox',
            'invoice_number' => $invoice,
            'credit_note_number' => 'CN-'.$id,
            'account' => $account,
            'seller' => 'seller_x',
            'currency' => 'DKK',
            'net_minor' => $gross,
            'tax_minor' => 0,
            'gross_minor' => $gross,
            'reason' => 'requested_by_customer',
            'ledger_transaction_id' => 'ltx_'.$id,
            'grant_reversal_id' => null,
            'kind' => 'refund',
            'gateway_status' => 'succeeded',
            'gateway_reference' => 'gw_'.$id,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
