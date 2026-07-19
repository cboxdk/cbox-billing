<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Metering\CumulativeUsageIngest;
use App\Models\ApiToken;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Metering\Contracts\AllowanceLeaseSource;
use Cbox\Billing\Metering\Contracts\Enforcement;
use Cbox\Billing\Metering\Contracts\EnforcementSignals;
use Cbox\Billing\Metering\Contracts\EventLog;
use Cbox\Billing\Metering\Contracts\LocalStore;
use Cbox\Billing\Metering\Contracts\MeterIngest;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\Contracts\UsageBuffer;
use Cbox\Billing\Metering\Enums\InfraFailurePolicy;
use Cbox\Billing\Metering\LeasedEnforcement;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The WHOLE billing lifecycle, exercised end to end through the real HTTP + command surface
 * in one flow — the seam every earlier feature test covers in isolation, wired together:
 *
 *   onboard + subscribe → meter on the hot path (reserve/commit + cumulative ingest) →
 *   reconcile → invoice (+ tax) → pay via a signed settlement webhook → upgrade → renew at
 *   the cycle boundary → cancel.
 *
 * Every step drives an engine-backed service or the enforcement/management/webhook API; the
 * only stand-in is the manual gateway (which would otherwise reach the network) settling
 * through its signed webhook. Real money (minor units), real wallet balances, and real
 * invoice numbers are asserted throughout — no faked state.
 */
class EndToEndBillingTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    private const ORG = 'org_e2e';

    protected function setUp(): void
    {
        parent::setUp();

        // The manual gateway is "connected" once a webhook secret is configured; without it
        // the verifier refuses every payload (deny-by-default).
        config()->set('billing.webhook.secret', self::WEBHOOK_SECRET);

        // Freeze the clock so the subscription period, the renewal boundary, and every issued
        // invoice date are deterministic. Mid-July → the opening period is all of July.
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        // The metering hot path stamps usage events off an injected millisecond clock that
        // defaults to the real wall clock; pin it to the frozen Carbon instant so committed
        // and ingested usage lands inside the frozen billing period regardless of the real
        // date the suite runs on.
        $this->freezeMeteringClock();

        // Only the catalog is seeded; the org, its subscription, and its wallet are all
        // created by the services under test.
        $this->seed(CatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_whole_billing_lifecycle_end_to_end(): void
    {
        // =================================================================================
        // 1. ONBOARD + SUBSCRIBE — the org subscribes to Team (20 seats) in DKK; the wallet
        //    is provisioned with the recurring credit allotment + every meter's included
        //    allowance, and the account currency pins on subscribe. Team is graduated (first
        //    10 seats free, then 9 900/seat), so 20 seats bill the seat-aware 99 000 DKK — the
        //    same figure MRR and the change preview compute, through the engine.
        // =================================================================================
        Organization::query()->create([
            'id' => self::ORG,
            'name' => 'Acme End-to-End',
            'billing_email' => 'billing@acme.example',
            'billing_country' => 'DK',
        ]);

        ['plaintext' => $token] = ApiToken::issue('e2e-sdk', self::ORG);
        $auth = ['Authorization' => 'Bearer '.$token];

        $subscribe = $this->postJson('/api/v1/subscriptions', [
            'org' => self::ORG,
            'plan' => 'team',
            'seats' => 20,
            'currency' => 'DKK',
        ], $auth);

        $subscribe->assertCreated()
            ->assertJsonPath('subscription.plan', 'team')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.seats', 20)
            // The manual gateway settles out of band, so there is no client-confirmable intent.
            ->assertJsonPath('payment_intent', null);

        // The account pinned its chosen currency on the first subscribe (the lock itself is
        // the FIRST FINALIZED INVOICE, asserted in step 3).
        $this->assertSame('DKK', Organization::query()->find(self::ORG)?->billing_currency);

        // The recurring included-credit allotment (Team = 250 000 credits) and each capped
        // meter's included allowance are now real, durable wallet lots (ADR-0013) — the
        // allowance is a spendable balance, not a hand-authored scalar.
        $this->assertSame(250_000, $this->included('credit'));       // recurring credit allotment
        $this->assertSame(1_000_000, $this->included('api.requests')); // included allowances…
        $this->assertSame(500_000, $this->included('events.ingested'));
        $this->assertSame(100, $this->included('storage.gb'));
        $this->assertSame(10, $this->included('seats'));

        // Durable: the lots live in the database wallet, so they survive a restart.
        $this->assertSame(5, DB::table('billing_wallet_lots')->where('org', self::ORG)->count());

        // The SDK caches these entitlements to enforce locally.
        $entitlements = $this->getJson('/api/v1/entitlements/'.self::ORG, $auth);
        $entitlements->assertOk();
        $meters = $entitlements->json('meters');
        $this->assertTrue($meters['api.requests']['enabled']);
        $this->assertSame(1_000_000, $meters['api.requests']['allowance']);
        $this->assertSame('bill', $meters['api.requests']['overage']);

        // =================================================================================
        // 2. METER ON THE HOT PATH — reserve/commit a multi-meter bucket set within
        //    allowance, drive an over-allowance meter into overage, and prove a hard-limit
        //    denial carries the upgrade deep-link.
        // =================================================================================

        // 2a. One all-or-nothing reservation across TWO meters, both within allowance.
        $reserve = $this->postJson('/api/v1/reserve', [
            'org' => self::ORG,
            'meters' => [
                ['meter' => 'api.requests', 'estimate' => 5_000],
                ['meter' => 'events.ingested', 'estimate' => 3_000],
            ],
        ], $auth);

        $reserve->assertOk()->assertJsonPath('outcome', 'allowed');
        $reservationId = $reserve->json('reservation_id');
        $this->assertIsString($reservationId);

        // Commit the actual usage (below the reserved estimate — the tail is returned).
        $this->postJson('/api/v1/commit', [
            'reservation_id' => $reservationId,
            'actuals' => [
                ['meter' => 'api.requests', 'actual' => 4_000],
                ['meter' => 'events.ingested', 'actual' => 2_000],
            ],
        ], $auth)->assertOk()->assertJsonPath('ok', true);

        // The committed usage is the durable metering truth.
        $eventLog = app(EventLog::class);
        $this->assertSame(4_000, $eventLog->sum(self::ORG, 'api.requests', 0, $this->usageWindowEndMs()));
        $this->assertSame(2_000, $eventLog->sum(self::ORG, 'events.ingested', 0, $this->usageWindowEndMs()));

        // 2b. Cumulative ingest drives events.ingested PAST its 500 000 allowance. The ingest
        //     is self-correcting: the durable sum converges to the latest cumulative reading
        //     (600 000) regardless of the 2 000 already committed above.
        $this->postJson('/api/v1/usage', [
            'org' => self::ORG,
            'entries' => [['meter' => 'events.ingested', 'cumulative' => 600_000, 'seq' => 1]],
        ], $auth)->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(600_000, $eventLog->sum(self::ORG, 'events.ingested', 0, $this->usageWindowEndMs()));

        // 2c. The burn-down order made visible on the usage summary: usage inside the included
        //     allowance is exempt (api.requests: 4 000 used, 0 overage), and only the excess
        //     over the allowance is overage (events.ingested: 600 000 used − 500 000 allowance
        //     = 100 000 overage). The credit wallet is the paid budget between the two.
        $summary = $this->getJson('/api/v1/usage/'.self::ORG, $auth);
        $summary->assertOk();
        $usageMeters = $summary->json('meters');

        $this->assertSame(4_000, $usageMeters['api.requests']['used']);
        $this->assertSame(1_000_000, $usageMeters['api.requests']['allowance']);
        $this->assertSame(0, $usageMeters['api.requests']['overage']);

        $this->assertSame(600_000, $usageMeters['events.ingested']['used']);
        $this->assertSame(500_000, $usageMeters['events.ingested']['allowance']);
        $this->assertSame(100_000, $usageMeters['events.ingested']['overage']);
        $this->assertSame(
            max(0, $usageMeters['events.ingested']['used'] - $usageMeters['events.ingested']['allowance']),
            $usageMeters['events.ingested']['overage'],
        );

        // 2d. A HARD-LIMIT denial: storage.gb is a Block meter capped at 100 GB on Team, so a
        //     reservation past the isolated allowance is refused (fail-closed) and carries the
        //     path to unlock — the cheapest reachable plan that lifts the cap (Business) and a
        //     pre-built hosted-checkout deep-link to buy it (ADR-0009/#52).
        $denied = $this->postJson('/api/v1/reserve', [
            'org' => self::ORG,
            'meters' => [['meter' => 'storage.gb', 'estimate' => 101]],
        ], $auth);

        $denied->assertOk()
            ->assertJsonPath('outcome', 'denied')
            ->assertJsonPath('reason', 'quota_exhausted')
            ->assertJsonPath('upgrade.required_plan', 'business');

        $checkoutUrl = $denied->json('upgrade.checkout_url');
        $this->assertIsString($checkoutUrl);
        $this->assertStringContainsString('/billing/checkout/', $checkoutUrl);

        // =================================================================================
        // 3. RECONCILE → INVOICE — converge durable usage into the ledger, then issue the
        //    period invoice: the plan fee taxed at DK 25%, under the seller's legal number.
        // =================================================================================
        $this->assertSame(0, Artisan::call('billing:reconcile-active'));

        $subscription = Subscription::query()->where('organization_id', self::ORG)->firstOrFail();
        $invoice = app(GeneratesInvoices::class)->generate($subscription->refresh());

        // Team graduated @ 20 seats is 99 000 DKK net (seat-aware, through the engine); DK
        // domestic B2B VAT is 25% → 24 750 tax, 123 750 gross.
        $this->assertSame('DKK', $invoice->currency);
        $this->assertSame(99_000, $invoice->subtotal_minor);
        $this->assertSame(24_750, $invoice->tax_minor);
        $this->assertSame(123_750, $invoice->total_minor);
        $this->assertSame('open', $invoice->status);
        // Per-seller legal number sequence (Cbox DK).
        $this->assertSame('CBOX-DK-2026-00001', $invoice->number);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id]);

        // The FIRST FINALIZED INVOICE locks the account's currency, one-way.
        $this->assertSame('DKK', app(BillingCurrencyLock::class)->lockedCurrency(self::ORG));

        // =================================================================================
        // 4. PAY VIA WEBHOOK — a `requires_action` event does NOT settle; a signed
        //    `payment.settled` marks the invoice paid exactly-once (a replay is a no-op).
        // =================================================================================

        // 4a. A requires-action notification is not a settlement — the invoice stays open.
        $requiresAction = $this->postSettlement([
            'event_id' => 'evt_requires_action',
            'type' => 'payment.requires_action',
            'reference' => $invoice->number,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);
        $requiresAction->assertOk()
            ->assertJsonPath('status', 'requires_action')
            ->assertJsonPath('applied', false);
        $this->assertFalse($invoice->refresh()->isPaid());

        // 4b. The settlement webhook marks it paid.
        $settled = $this->postSettlement([
            'event_id' => 'evt_settled',
            'type' => 'payment.settled',
            'reference' => $invoice->number,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);
        $settled->assertOk()->assertJsonPath('applied', true)->assertJsonPath('status', 'applied');
        $this->assertTrue($invoice->refresh()->isPaid());
        $this->assertSame('evt_settled', $invoice->gateway_reference);

        // 4c. Exactly-once: a re-delivery of the same settlement is a safe no-op.
        $this->postSettlement([
            'event_id' => 'evt_settled',
            'type' => 'payment.settled',
            'reference' => $invoice->number,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ])->assertOk()->assertJsonPath('applied', false);

        // The subscription is active and paid up.
        $this->getJson('/api/v1/subscriptions/'.self::ORG, $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('plan', 'team');

        // =================================================================================
        // 5. UPGRADE — preview the change to Business (preview == charge), assert the credit
        //    delta (outgoing 250 000 allotment forfeited, incoming 1 000 000 granted), apply.
        // =================================================================================
        $preview = $this->postJson('/api/v1/subscriptions/'.self::ORG.'/preview', ['plan' => 'business'], $auth);
        $preview->assertOk()
            ->assertJsonPath('new_recurring_minor', 349_000)
            ->assertJsonPath('credit_delta.forfeited', 250_000)   // the outgoing Team allotment
            ->assertJsonPath('credit_delta.granted', 1_000_000)   // the incoming Business allotment
            ->assertJsonPath('credit_delta.carried', 0);

        $dueNow = $preview->json('due_now_minor');
        $this->assertIsInt($dueNow);
        $this->assertGreaterThan(0, $dueNow);
        // Mid-cycle proration of the plan delta, taxed: below the full recurring difference.
        $this->assertLessThan(349_000 - 124_000, $dueNow);

        // Apply the change: the charge is IDENTICAL to the preview (the preview IS the charge).
        $change = $this->postJson('/api/v1/subscriptions/'.self::ORG.'/change', ['plan' => 'business'], $auth);
        $change->assertOk()
            ->assertJsonPath('scheduled', false)
            ->assertJsonPath('new_recurring_minor', 349_000);
        $this->assertSame($dueNow, $change->json('due_now_minor'));

        // H6 — the immediate upgrade COLLECTS the previewed due-now: a prorated invoice is
        // issued (the second legal number) for exactly the taxed amount the preview promised,
        // so preview == charge holds in cash, not just on the review page. Before this fix the
        // upgrade was provisioned free.
        $prorationInvoice = Invoice::query()->where('organization_id', self::ORG)->orderByDesc('id')->firstOrFail();
        $this->assertSame('CBOX-DK-2026-00002', $prorationInvoice->number);
        $this->assertSame($dueNow, $prorationInvoice->total_minor);
        $this->assertSame(2, Invoice::query()->where('organization_id', self::ORG)->count());

        // The wallet reset (ADR-0011): the outgoing included allotment was forfeited before the
        // incoming one was granted, so the balance is the new plan's 1 000 000, never the sum.
        $this->getJson('/api/v1/subscriptions/'.self::ORG, $auth)->assertJsonPath('plan', 'business');
        $this->assertSame(1_000_000, $this->included('credit'));

        // A purchased (pay-as-you-go) top-up survives everything that forfeits the included
        // allotment — granted here so its survival through renew + cancel is real, not implied.
        app(Wallet::class)->grant(new CreditGrant(
            id: self::ORG.':payg:topup',
            org: self::ORG,
            pool: Pools::purchased(),
            denomination: Denomination::unit('credit'),
            remaining: 7_500,
            expiresAt: null,
        ));
        $this->assertSame(7_500, $this->balance(Pools::purchased(), 'credit'));

        // =================================================================================
        // 6. RENEW — cross the cycle boundary and run `billing:renew`: the new cycle's
        //    allotments are granted idempotently (a re-run is a no-op) and the renewal
        //    invoice is issued for the now-Business subscription.
        // =================================================================================
        $lotsBeforeRenew = DB::table('billing_wallet_lots')->where('org', self::ORG)->count();

        // Roll onto the next period boundary (July 31 23:59:59 < Aug 1 00:00:00).
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:00', 'UTC'));

        $this->assertSame(0, Artisan::call('billing:renew'));

        // The base rolled over onto August, and the new cycle's 1 000 000 Business allotment
        // is granted (the July EndOfPeriod lot was swept, so the balance is the new cycle's,
        // not both). A fresh lot was deposited.
        $renewed = Subscription::query()->where('organization_id', self::ORG)->firstOrFail();
        $this->assertSame('2026-08-01', $renewed->current_period_start?->format('Y-m-d'));
        $this->assertSame('2026-09-01', $renewed->current_period_end?->format('Y-m-d'));
        $this->assertSame(1_000_000, $this->included('credit'));
        $this->assertGreaterThan($lotsBeforeRenew, DB::table('billing_wallet_lots')->where('org', self::ORG)->count());

        // The renewal issued the THIRD legal invoice (after the team period invoice and the
        // upgrade proration) — Business is volume-priced at 12 000/seat in its first tier, so
        // @ 20 seats the seat-aware charge is 240 000 + 25% = 300 000 (through the engine, not
        // the 349 000 base list price).
        $renewalInvoice = Invoice::query()->where('organization_id', self::ORG)->orderByDesc('id')->firstOrFail();
        $this->assertSame('CBOX-DK-2026-00003', $renewalInvoice->number);
        $this->assertSame(240_000, $renewalInvoice->subtotal_minor);
        $this->assertSame(60_000, $renewalInvoice->tax_minor);
        $this->assertSame(300_000, $renewalInvoice->total_minor);
        $this->assertSame(3, Invoice::query()->where('organization_id', self::ORG)->count());

        // Idempotent: a second run at the same instant grants nothing and issues no duplicate
        // invoice — the period has already advanced, so nothing is due.
        $lotsAfterRenew = DB::table('billing_wallet_lots')->where('org', self::ORG)->count();
        $this->assertSame(0, Artisan::call('billing:renew'));
        $this->assertSame(1_000_000, $this->included('credit'));
        $this->assertSame($lotsAfterRenew, DB::table('billing_wallet_lots')->where('org', self::ORG)->count());
        $this->assertSame(3, Invoice::query()->where('organization_id', self::ORG)->count());

        // =================================================================================
        // 7. CANCEL — an immediate cancel runs the engine's forfeiture-on-transition: the
        //    forfeitable included pool floors at 0, while purchased/PAYG credit survives.
        // =================================================================================
        $this->assertSame(1_000_000, $this->included('credit'));
        $this->assertSame(7_500, $this->balance(Pools::purchased(), 'credit'));

        $this->postJson('/api/v1/subscriptions/'.self::ORG.'/cancel', ['at_period_end' => false], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'canceled');

        // Forfeiture-on-transition: the included allotment is gone; the purchased balance stands.
        $this->assertSame(0, $this->included('credit'));
        $this->assertSame(7_500, $this->balance(Pools::purchased(), 'credit'));

        // Deny-by-default after cancel: the subscription no longer resolves, so it is not
        // surfaced as active.
        $this->getJson('/api/v1/subscriptions/'.self::ORG, $auth)->assertNotFound();
    }

    /**
     * Rebind the two metering services that carry their own millisecond clock so both stamp
     * usage events off the frozen Carbon instant instead of `microtime()`.
     */
    private function freezeMeteringClock(): void
    {
        $clock = static fn (): int => (int) (Carbon::now()->getTimestamp() * 1000);

        $this->app->singleton(Enforcement::class, static fn (Application $app): LeasedEnforcement => new LeasedEnforcement(
            store: $app->make(LocalStore::class),
            source: $app->make(AllowanceLeaseSource::class),
            buffer: $app->make(UsageBuffer::class),
            service: 'api',
            clock: $clock,
            policies: $app->make(MeterPolicyResolver::class),
            signals: $app->make(EnforcementSignals::class),
            infraPolicy: $app->make(InfraFailurePolicy::class),
        ));

        $this->app->bind(CumulativeUsageIngest::class, static fn (Application $app): CumulativeUsageIngest => new CumulativeUsageIngest(
            $app->make(EventLog::class),
            $app->make(MeterIngest::class),
            'api',
            $clock,
        ));
    }

    /** The whole frozen billing window, wide enough to sum every stamped usage event. */
    private function usageWindowEndMs(): int
    {
        return (int) (Carbon::now()->getTimestamp() * 1000) + 1;
    }

    /**
     * Post a signed settlement webhook exactly as a gateway (or the operator, out of band)
     * would — the raw bytes are what the HMAC is computed over, matching the manual verifier.
     *
     * @param  array<string, mixed>  $event
     */
    private function postSettlement(array $event): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            '/webhooks/manual',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CBOX_SIGNATURE' => $signature],
            $body,
        );
    }

    /** The org's balance in the `included` pool for a denomination (credit or a meter unit). */
    private function included(string $denomination): int
    {
        return $this->balance(Pools::included(), $denomination);
    }

    private function balance(Pool $pool, string $denomination): int
    {
        return app(Wallet::class)->balance(
            self::ORG,
            $pool,
            Denomination::unit($denomination),
            (int) (Carbon::now()->getTimestamp() * 1000),
        );
    }
}
