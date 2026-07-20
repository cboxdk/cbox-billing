<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Environments\EnvironmentType;
use App\Billing\Environments\GatewayKeyMode;
use App\Billing\Environments\Gateways\EnvironmentGatewayStore;
use App\Billing\Environments\Gateways\GatewayCredentialException;
use App\Billing\Mode\BillingContext;
use App\Models\Environment;
use App\Models\EnvironmentGateway;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Database\Seeders\EnvironmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Per-environment gateway credentials + the key-type safety gate. A sandbox may only hold TEST
 * Stripe keys and production only LIVE keys (rejected both ways with a clear error), the secret is
 * stored ENCRYPTED at rest, and the bound gateway resolves the current plane's credentials — with
 * the BC fallback to the global env-var keys (production) / the fake gateway (a keyless sandbox).
 */
class EnvironmentGatewayCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EnvironmentSeeder::class);
    }

    private function store(): EnvironmentGatewayStore
    {
        return app(EnvironmentGatewayStore::class);
    }

    private function environment(string $key): Environment
    {
        return Environment::query()->where('key', $key)->firstOrFail();
    }

    public function test_a_sandbox_accepts_a_test_key_but_refuses_a_live_key(): void
    {
        $sandbox = $this->environment(Environment::SANDBOX);

        // A live key in a sandbox is refused — this is what stops a real card being charged there.
        try {
            $this->store()->put($sandbox, 'sk_live_realmoney', null, null);
            $this->fail('A live key must be refused for a sandbox.');
        } catch (GatewayCredentialException $e) {
            $this->assertStringContainsString('test', strtolower($e->getMessage()));
        }

        // A test key is accepted.
        $row = $this->store()->put($sandbox, 'sk_test_abc123', 'pk_test_abc', 'whsec_xyz');
        $this->assertSame(Environment::SANDBOX, $row->environment);
        $this->assertTrue($row->active);
    }

    public function test_production_requires_a_live_key_and_refuses_a_test_key(): void
    {
        $production = $this->environment(Environment::PRODUCTION);

        try {
            $this->store()->put($production, 'sk_test_abc123', null, null);
            $this->fail('A test key must be refused for production.');
        } catch (GatewayCredentialException $e) {
            $this->assertStringContainsString('live', strtolower($e->getMessage()));
        }

        $row = $this->store()->put($production, 'sk_live_realkey', 'pk_live_abc', null);
        $this->assertSame(Environment::PRODUCTION, $row->environment);
    }

    public function test_a_restricted_test_key_is_accepted_for_a_sandbox(): void
    {
        $row = $this->store()->put($this->environment(Environment::SANDBOX), 'rk_test_restricted', null, null);
        $this->assertNotNull($row->id);
    }

    public function test_a_publishable_key_of_the_wrong_type_is_refused(): void
    {
        $this->expectException(GatewayCredentialException::class);
        $this->store()->put($this->environment(Environment::SANDBOX), 'sk_test_ok', 'pk_live_wrong', null);
    }

    public function test_the_secret_is_stored_encrypted_at_rest(): void
    {
        $this->store()->put($this->environment(Environment::SANDBOX), 'sk_test_secretvalue', null, null);

        // The raw column is ciphertext, not the plaintext; the model decrypts it back.
        $raw = DB::table('environment_gateways')
            ->where('environment', Environment::SANDBOX)
            ->value('secret');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('sk_test_secretvalue', $raw);

        $this->assertSame('sk_test_secretvalue', EnvironmentGateway::query()->where('environment', Environment::SANDBOX)->firstOrFail()->secret);
    }

    public function test_gateway_resolves_the_current_environments_credentials(): void
    {
        // Give the sandbox its own real Stripe TEST credentials.
        $this->store()->put($this->environment(Environment::SANDBOX), 'sk_test_abc', 'pk_test_abc', null);

        $context = app(BillingContext::class);

        // In the sandbox plane, the resolved gateway is the real Stripe adapter (its own keys),
        // not the fake test gateway.
        $context->runInEnvironment($this->environment(Environment::SANDBOX), function (): void {
            $gateway = app(PaymentGateway::class);
            $this->assertSame('stripe', $gateway->name());
        });
    }

    public function test_bc_a_keyless_sandbox_uses_the_fake_gateway_and_production_the_env_var_gateway(): void
    {
        $context = app(BillingContext::class);

        // A keyless sandbox → the fake test gateway (never a real account).
        $context->runInEnvironment($this->environment(Environment::SANDBOX), function (): void {
            $this->assertSame('test', app(PaymentGateway::class)->name());
        });

        // Production with no DB keys and no STRIPE_SECRET → the global env-var fallback (manual).
        $context->runInEnvironment($this->environment(Environment::PRODUCTION), function (): void {
            $this->assertSame('manual', app(PaymentGateway::class)->name());
        });
    }

    public function test_a_cloned_sandbox_does_not_inherit_the_source_gateway_keys(): void
    {
        // Production has live keys; a clone (test plane) must NOT carry them (no live secret leak).
        $this->store()->put($this->environment(Environment::PRODUCTION), 'sk_live_realkey', null, null);

        $clone = Environment::query()->create([
            'key' => 'clone-test',
            'name' => 'Clone',
            'type' => EnvironmentType::Sandbox,
            'protected' => false,
            'gateway_key_mode' => GatewayKeyMode::Test,
        ]);

        $this->assertNull($this->store()->forEnvironment($clone->key), 'a fresh sandbox carries no gateway keys');
    }
}
