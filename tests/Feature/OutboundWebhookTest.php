<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Delivery\WebhookDeliverer;
use App\Webhooks\Enums\DeliveryStatus;
use App\Webhooks\Enums\WebhookEvent;
use App\Webhooks\Exceptions\UnsafeWebhookUrl;
use App\Webhooks\Jobs\DeliverWebhook;
use App\Webhooks\Support\WebhookSignature;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookEndpointRegistry;
use Cbox\Billing\Events\InvoiceIssued;
use Cbox\Billing\Events\PaymentSettled;
use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Quote\ValueObjects\QuoteTotals;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\TaxProfile;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The outbound webhook / event bus: emit → sign → deliver, retried, SSRF-safe, gated console CRUD.
 * Real vectors — the delivered body is verified with the app's OWN inbound HMAC primitive, the SSRF
 * guard is asserted at both registration and delivery, and retry/dead-letter/idempotency are driven
 * end-to-end.
 */
class OutboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        // Off by default so a real DNS resolution never runs in the delivery tests; the SSRF tests
        // turn it back on explicitly.
        config(['cbox-billing.webhooks.verify_url' => false]);
    }

    // ---- 1. Emission: the right endpoints, and only those, get exactly one delivery ----

    public function test_an_engine_invoice_issued_enqueues_one_delivery_with_the_correct_type_and_payload(): void
    {
        Bus::fake();
        $endpoint = $this->endpoint([WebhookEvent::InvoiceIssued->value]);

        event(new InvoiceIssued($this->invoice('CBX-2026-0001', 'org_acme'), 'org_acme'));

        Bus::assertDispatchedTimes(DeliverWebhook::class, 1);
        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame($endpoint->id, $delivery->endpoint_id);
        $this->assertSame('invoice.issued', $delivery->event_type);
        $this->assertSame('invoice:CBX-2026-0001', $delivery->event_id);
        $this->assertSame('CBX-2026-0001', $delivery->payload['number']);
        $this->assertSame('org_acme', $delivery->payload['account']);
    }

    public function test_an_engine_payment_settled_enqueues_one_delivery_with_the_correct_type_and_payload(): void
    {
        Bus::fake();
        $this->endpoint([WebhookEvent::PaymentSettled->value]);

        event(new PaymentSettled('inv_777', Money::ofMinor(4200, 'DKK'), PaymentResult::succeeded('gw_abc')));

        Bus::assertDispatchedTimes(DeliverWebhook::class, 1);
        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame('payment.settled', $delivery->event_type);
        $this->assertSame(['minor' => 4200, 'currency' => 'DKK'], $delivery->payload['amount']);
        $this->assertSame('gw_abc', $delivery->payload['gateway_reference']);
    }

    public function test_an_endpoint_not_subscribed_to_the_type_gets_nothing(): void
    {
        Bus::fake();
        $this->endpoint([WebhookEvent::PaymentSettled->value]); // subscribed to a different type

        event(new InvoiceIssued($this->invoice('CBX-1', 'org_x'), 'org_x'));

        Bus::assertNotDispatched(DeliverWebhook::class);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_an_inactive_endpoint_gets_nothing(): void
    {
        Bus::fake();
        $this->endpoint([WebhookEvent::InvoiceIssued->value], active: false);

        event(new InvoiceIssued($this->invoice('CBX-1', 'org_x'), 'org_x'));

        Bus::assertNotDispatched(DeliverWebhook::class);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_re_emitting_the_same_event_does_not_double_deliver(): void
    {
        Bus::fake();
        $this->endpoint([WebhookEvent::PaymentSettled->value]);

        $fire = fn () => event(new PaymentSettled('inv_dup', Money::ofMinor(100, 'DKK'), PaymentResult::succeeded('gw')));
        $fire();
        $fire();

        Bus::assertDispatchedTimes(DeliverWebhook::class, 1);
        $this->assertSame(1, WebhookDelivery::query()->count());
    }

    // ---- 2. The delivered body verifies with the app's OWN inbound HMAC primitive ----

    public function test_the_delivered_body_hmac_verifies_symmetrically_and_a_tampered_body_fails(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $secret = WebhookEndpoint::newSecret();
        $endpoint = $this->endpoint([WebhookEvent::PaymentSettled->value], secret: $secret);
        $delivery = $this->pendingDelivery($endpoint, WebhookEvent::PaymentSettled->value);

        app(WebhookDeliverer::class)->deliver($delivery);

        [$body, $sigHeader] = $this->captureSent();

        // Verified with the shared signer (symmetric with the inbound ManualWebhookVerifier).
        $this->assertTrue(WebhookSignature::verify($body, $secret, $sigHeader));

        // And with the app's OWN inbound primitive, verbatim: hash_hmac over `t.body` + hash_equals.
        $parsed = WebhookSignature::parse($sigHeader);
        $this->assertNotNull($parsed);
        [$t, $v1] = $parsed;
        $this->assertTrue(hash_equals(hash_hmac('sha256', $t.'.'.$body, $secret), $v1));

        // A tampered body no longer verifies.
        $this->assertFalse(WebhookSignature::verify($body.'x', $secret, $sigHeader));
        // Nor does the right body under the wrong secret.
        $this->assertFalse(WebhookSignature::verify($body, WebhookEndpoint::newSecret(), $sigHeader));
    }

    public function test_rotating_the_secret_changes_the_signature(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $endpoint = $this->endpoint([WebhookEvent::PaymentSettled->value], secret: WebhookEndpoint::newSecret());

        app(WebhookDeliverer::class)->deliver($this->pendingDelivery($endpoint, WebhookEvent::PaymentSettled->value));
        [$body, $before] = $this->captureSent();

        app(WebhookEndpointRegistry::class)->rotateSecret($endpoint);
        Http::fake(['*' => Http::response('', 200)]);
        app(WebhookDeliverer::class)->deliver($this->pendingDelivery($endpoint->fresh(), WebhookEvent::PaymentSettled->value, 'inv_2'));
        [, $after] = $this->captureSent();

        $this->assertNotSame($before, $after);
    }

    // ---- 3. SSRF: refused at registration AND at delivery (TOCTOU-closed) ----

    /**
     * @return list<array{0: string}>
     */
    public static function unsafeUrls(): array
    {
        return [['http://127.0.0.1/hook'], ['http://169.254.169.254/latest/meta-data'], ['http://10.0.0.5/hook']];
    }

    #[DataProvider('unsafeUrls')]
    public function test_registration_refuses_a_non_public_url(string $url): void
    {
        config(['cbox-billing.webhooks.verify_url' => true]);

        $this->expectException(UnsafeWebhookUrl::class);
        app(WebhookEndpointRegistry::class)->register($url, [WebhookEvent::PaymentSettled->value], null, null);
    }

    public function test_delivery_refuses_a_url_that_now_resolves_to_a_private_address(): void
    {
        // TOCTOU: the row exists (as if the URL was public at registration) but now points at an
        // internal address. Enforcement is on, so the delivery is refused before any connect.
        config(['cbox-billing.webhooks.verify_url' => true]);
        Http::fake();

        $endpoint = new WebhookEndpoint;
        $endpoint->fill([
            'url' => 'http://10.0.0.5/hook', 'secret' => WebhookEndpoint::newSecret(),
            'active' => true, 'event_types' => [WebhookEvent::PaymentSettled->value],
        ])->save();

        $delivery = $this->pendingDelivery($endpoint, WebhookEvent::PaymentSettled->value);
        app(WebhookDeliverer::class)->deliver($delivery);

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertNull($delivery->response_code);
        $this->assertNotNull($delivery->next_retry_at);
    }

    // ---- 4. Retry, dead-letter, redeliver, stable delivery id ----

    public function test_a_failed_post_is_retried_then_dead_lettered_and_the_delivery_id_is_stable(): void
    {
        config(['cbox-billing.webhooks.max_attempts' => 2]);
        Http::fake(['*' => Http::response('nope', 500)]);
        $endpoint = $this->endpoint([WebhookEvent::PaymentSettled->value]);
        $delivery = $this->pendingDelivery($endpoint, WebhookEvent::PaymentSettled->value);
        $originalId = $delivery->id;

        app(WebhookDeliverer::class)->deliver($delivery);
        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(500, $delivery->response_code);
        $this->assertSame(1, $delivery->attempt);
        $this->assertNotNull($delivery->next_retry_at);

        app(WebhookDeliverer::class)->deliver($delivery);
        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Dead, $delivery->status);
        $this->assertSame(2, $delivery->attempt);
        $this->assertNull($delivery->next_retry_at);

        // The delivery_id a receiver dedupes on never changed across the attempts.
        $this->assertSame($originalId, $delivery->id);
    }

    public function test_redeliver_re_attempts_a_dead_delivery(): void
    {
        config(['cbox-billing.webhooks.max_attempts' => 1]);
        // First attempt fails (500), the receiver recovers for the redeliver (200).
        Http::fakeSequence()->push('nope', 500)->push('', 200);
        $endpoint = $this->endpoint([WebhookEvent::PaymentSettled->value]);
        $delivery = $this->pendingDelivery($endpoint, WebhookEvent::PaymentSettled->value);

        app(WebhookDeliverer::class)->deliver($delivery);
        $this->assertSame(DeliveryStatus::Dead, $delivery->fresh()?->status);

        // The receiver is back — a manual redeliver succeeds (the sync queue runs the job inline).
        app(WebhookEndpointRegistry::class)->redeliver($delivery->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()?->status);
    }

    public function test_the_retry_sweep_re_attempts_only_due_failed_deliveries(): void
    {
        config(['cbox-billing.webhooks.max_attempts' => 5]);
        Http::fakeSequence()->push('nope', 500)->push('', 200);
        $endpoint = $this->endpoint([WebhookEvent::PaymentSettled->value]);
        $delivery = $this->pendingDelivery($endpoint, WebhookEvent::PaymentSettled->value);
        app(WebhookDeliverer::class)->deliver($delivery); // now failed, next_retry_at in the future

        // Make it due, then sweep with a now-healthy receiver.
        $delivery->forceFill(['next_retry_at' => now()->subMinute()])->save();
        $swept = app(WebhookDispatcher::class)->retryPending();

        $this->assertSame(1, $swept);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()?->status);
    }

    // ---- 5. Test event (ping) ----

    public function test_sending_a_test_event_delivers_a_signed_ping(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $secret = WebhookEndpoint::newSecret();
        $endpoint = $this->endpoint([WebhookEvent::PaymentSettled->value], secret: $secret);

        app(WebhookEndpointRegistry::class)->sendTest($endpoint);

        [$body, $sig] = $this->captureSent();
        $this->assertTrue(WebhookSignature::verify($body, $secret, $sig));
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ping', $decoded['type']);

        $delivery = WebhookDelivery::query()->where('event_type', 'ping')->sole();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
    }

    public function test_a_delivery_resolves_the_most_specific_subject_link_from_its_payload(): void
    {
        // Invoice event → invoice (most specific).
        $paymentFailed = (new WebhookDelivery)->forceFill([
            'event_type' => 'payment.failed',
            'payload' => ['invoice_id' => 42, 'organization_id' => 'org_acme', 'subscription_id' => 7],
        ]);
        $this->assertSame(
            ['label' => 'Invoice #42', 'route' => 'billing.invoices.show', 'param' => 42],
            $paymentFailed->subjectLink(),
        );

        // Subscription event carries the id as `id`.
        $subCreated = (new WebhookDelivery)->forceFill([
            'event_type' => 'subscription.created',
            'payload' => ['id' => 7, 'organization_id' => 'org_acme'],
        ]);
        $this->assertSame(
            ['label' => 'Subscription #7', 'route' => 'billing.subscriptions.show', 'param' => 7],
            $subCreated->subjectLink(),
        );

        // Coupon redeemed → the subscription it applied to.
        $couponRedeemed = (new WebhookDelivery)->forceFill([
            'event_type' => 'coupon.redeemed',
            'payload' => ['subscription_id' => 9, 'organization_id' => 'org_acme'],
        ]);
        $this->assertSame('billing.subscriptions.show', $couponRedeemed->subjectLink()['route'] ?? null);

        // Only an organization → the customer.
        $orgOnly = (new WebhookDelivery)->forceFill([
            'event_type' => 'dunning.exhausted',
            'payload' => ['organization_id' => 'org_acme'],
        ]);
        $this->assertSame(
            ['label' => 'org_acme', 'route' => 'billing.customers.show', 'param' => 'org_acme'],
            $orgOnly->subjectLink(),
        );

        // A ping (no subject) → null, so the log falls back to the event id.
        $ping = (new WebhookDelivery)->forceFill(['event_type' => 'ping', 'payload' => ['nonce' => 'abc']]);
        $this->assertNull($ping->subjectLink());
    }

    // ---- 6. Console CRUD + permission gate ----

    public function test_the_console_registers_an_endpoint_and_shows_the_secret_once(): void
    {
        $response = $this->withSession($this->session)->post('/settings/webhooks', [
            'url' => 'https://api.example.com/hooks',
            'description' => 'Prod sync',
            'event_types' => [WebhookEvent::InvoiceIssued->value, WebhookEvent::PaymentSettled->value],
        ]);

        $response->assertOk();
        $revealed = $response->viewData('revealed');
        $this->assertIsArray($revealed);
        $this->assertIsString($revealed['secret']);
        // Rendered directly (SEC-3) — never flashed through the session.
        $response->assertSessionMissing('revealed');
        $response->assertSee($revealed['secret'], false);

        $endpoint = WebhookEndpoint::query()->sole();
        $this->assertSame('https://api.example.com/hooks', $endpoint->url);
        $this->assertEqualsCanonicalizing(
            [WebhookEvent::InvoiceIssued->value, WebhookEvent::PaymentSettled->value],
            $endpoint->event_types,
        );
        // The secret is stored encrypted, not in plaintext.
        $this->assertNotSame($revealed['secret'], $endpoint->getRawOriginal('secret'));
        $this->assertSame($revealed['secret'], $endpoint->secret);
    }

    public function test_the_register_form_and_delivery_log_views_render(): void
    {
        $this->withSession($this->session)->get('/settings/webhooks/new')
            ->assertOk()
            ->assertSee('Subscribed events')
            ->assertSee('invoice.issued');

        $endpoint = $this->endpoint([WebhookEvent::PaymentSettled->value]);
        $this->pendingDelivery($endpoint, WebhookEvent::PaymentSettled->value);

        $this->withSession($this->session)->get('/settings/webhooks/'.$endpoint->id)
            ->assertOk()
            ->assertSee('Recent deliveries')
            ->assertSee('payment.settled');
    }

    public function test_the_console_refuses_registering_a_private_url(): void
    {
        config(['cbox-billing.webhooks.verify_url' => true]);

        $this->withSession($this->session)
            ->post('/settings/webhooks', ['url' => 'http://127.0.0.1/x', 'event_types' => [WebhookEvent::PaymentSettled->value]])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_writes_are_gated_by_the_settings_manage_permission_when_rbac_is_enforced(): void
    {
        config(['billing.rbac.enforce' => true]);
        $reader = ['auth.user' => $this->session['auth.user'] + ['permissions' => ['settings:read']]];

        // A reader may list, but not register.
        $this->withSession($reader)->get('/settings/webhooks')->assertOk();
        $this->withSession($reader)->post('/settings/webhooks', [
            'url' => 'https://api.example.com/hooks', 'event_types' => [WebhookEvent::PaymentSettled->value],
        ])->assertForbidden();

        // With settings:manage the write is allowed.
        $manager = ['auth.user' => $this->session['auth.user'] + ['permissions' => ['settings:read', 'settings:manage']]];
        $this->withSession($manager)->post('/settings/webhooks', [
            'url' => 'https://api.example.com/hooks', 'event_types' => [WebhookEvent::PaymentSettled->value],
        ])->assertOk();

        $this->assertSame(1, WebhookEndpoint::query()->count());
    }

    // ---- helpers ----

    /**
     * @param  list<string>  $types
     */
    private function endpoint(array $types, bool $active = true, ?string $secret = null): WebhookEndpoint
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->fill([
            'url' => 'https://receiver.example.com/hook',
            'secret' => $secret ?? WebhookEndpoint::newSecret(),
            'active' => $active,
            'event_types' => $types,
        ])->save();

        return $endpoint;
    }

    private function pendingDelivery(WebhookEndpoint $endpoint, string $type, string $eventId = 'inv_1'): WebhookDelivery
    {
        return WebhookDelivery::query()->create([
            'endpoint_id' => $endpoint->id,
            'event_type' => $type,
            'event_id' => $eventId,
            'payload' => ['reference' => $eventId, 'amount' => ['minor' => 100, 'currency' => 'DKK']],
            'attempt' => 0,
            'status' => DeliveryStatus::Pending,
        ]);
    }

    /**
     * The body + signature header of the single sent HTTP request.
     *
     * @return array{0: string, 1: string}
     */
    private function captureSent(): array
    {
        $body = '';
        $sig = '';

        Http::assertSent(function ($request) use (&$body, &$sig): bool {
            $body = $request->body();
            $sig = $request->header(WebhookSignature::SIGNATURE_HEADER)[0] ?? '';

            return true;
        });

        return [$body, $sig];
    }

    private function invoice(string $number, string $account): Invoice
    {
        $seller = new SellerEntity('seller-1', 'Seller Co', 'REG-1', new CountryCode('DK'), 'DKK', 'INV');
        $place = new Jurisdiction(new CountryCode('DK'), 'Denmark', 'DKK', TaxProfile::notModeled());
        $zero = Money::zero('DKK');
        $totals = new QuoteTotals($zero, $zero, $zero, $zero, $zero);

        return new Invoice($number, $seller, $place, 'DKK', [], $totals, new DateTimeImmutable('2026-07-19T10:00:00+00:00'));
    }
}
