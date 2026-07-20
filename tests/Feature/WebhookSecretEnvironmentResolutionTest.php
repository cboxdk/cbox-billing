<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Mode\BillingContext;
use App\Billing\Payments\EnvironmentAwareWebhookVerifier;
use App\Models\Environment;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The settlement webhook verifier resolves its Stripe signing secret from the CURRENT environment:
 * a plane configured with its OWN DB webhook secret verifies webhooks signed with that secret and
 * rejects ones signed with the global env-var secret — and a plane WITHOUT a DB secret falls back
 * to the global secret. The secret is resolved per call, so two planes never cross-verify.
 */
class WebhookSecretEnvironmentResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const GLOBAL_SECRET = 'whsec_global_secret';

    private const DB_SECRET = 'whsec_plane_secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);

        // The global env-var Stripe webhook secret (the single-plane default fallback).
        config(['billing-stripe.webhook_secret' => self::GLOBAL_SECRET]);
    }

    private function signedWith(string $secret, string $reference = 'DK-000001'): WebhookPayload
    {
        $body = (string) json_encode([
            'id' => 'evt_test_123',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_test_123',
                'object' => 'payment_intent',
                'amount' => 12500,
                'currency' => 'eur',
                'status' => 'succeeded',
                'metadata' => ['reference' => $reference],
            ]],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return new WebhookPayload($body, ['Stripe-Signature' => "t={$timestamp},v1={$signature}"]);
    }

    private function verifier(): EnvironmentAwareWebhookVerifier
    {
        $verifier = app(WebhookVerifier::class);
        $this->assertInstanceOf(EnvironmentAwareWebhookVerifier::class, $verifier);

        return $verifier;
    }

    private function inEnvironment(string $key, callable $callback): mixed
    {
        $environment = Environment::query()->where('key', $key)->firstOrFail();

        return app(BillingContext::class)->runInEnvironment($environment, $callback);
    }

    public function test_a_plane_with_a_db_secret_verifies_its_own_and_rejects_the_global(): void
    {
        // Configure a sandbox plane with its OWN Stripe webhook secret in the DB gateway store.
        $sandbox = Environment::query()->where('key', 'sandbox')->firstOrFail();
        app(EnvironmentGatewayStore::class)->put($sandbox, secret: 'sk_test_x', publishable: null, webhookSecret: self::DB_SECRET);

        $verifier = $this->verifier();

        // In the sandbox plane, the DB secret verifies; the global secret is rejected.
        $this->inEnvironment('sandbox', function () use ($verifier): void {
            $event = $verifier->verify($this->signedWith(self::DB_SECRET));
            $this->assertSame('evt_test_123', $event->id);

            $this->expectException(WebhookVerificationFailed::class);
            $verifier->verify($this->signedWith(self::GLOBAL_SECRET));
        });
    }

    public function test_a_keyless_plane_falls_back_to_the_global_secret(): void
    {
        $verifier = $this->verifier();

        // Production has no DB webhook secret → the global env-var secret verifies, and a payload
        // signed with a plane-specific secret it does not hold is rejected.
        $this->inEnvironment('production', function () use ($verifier): void {
            $event = $verifier->verify($this->signedWith(self::GLOBAL_SECRET));
            $this->assertSame('evt_test_123', $event->id);

            $this->expectException(WebhookVerificationFailed::class);
            $verifier->verify($this->signedWith(self::DB_SECRET));
        });
    }
}
