<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Operator wallet adjustments (Wave 3): a grant raises the balance in the correct pool
 * (exact units), a debit lowers it, the audit row + reason persist, and an over-debit of a
 * pool that may not go negative is refused. Everything writes through the engine wallet.
 */
class WalletAdjustmentConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-18 10:00:00');
        Organization::query()->create(['id' => 'org_w', 'name' => 'Wallet Co', 'billing_email' => 'w@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
    }

    private function balance(string $pool): int
    {
        return app(Wallet::class)->balance(
            'org_w',
            $pool === 'purchased' ? Pools::purchased() : Pools::promotional(),
            Denomination::unit('credit'),
            (int) (Carbon::now()->getTimestamp() * 1000),
        );
    }

    public function test_grant_raises_the_balance_in_the_correct_pool(): void
    {
        $this->assertSame(0, $this->balance('promotional'));

        $this->withSession($this->session)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'promotional', 'denomination' => 'credit',
            'amount' => 2500, 'reason' => 'Goodwill for outage', 'expires_in_days' => 30,
        ])->assertRedirect('/customers/org_w')->assertSessionHas('status');

        $this->assertSame(2500, $this->balance('promotional'));
        $this->assertDatabaseHas('wallet_adjustments', [
            'organization_id' => 'org_w', 'pool_key' => 'promotional', 'amount' => 2500,
            'direction' => 'grant', 'reason' => 'Goodwill for outage', 'actor' => 'ops@example.test',
        ]);
    }

    public function test_debit_lowers_the_balance_and_records_a_negative_audit(): void
    {
        // Seed a purchased top-up, then debit part of it.
        $this->withSession($this->session)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'purchased', 'denomination' => 'credit',
            'amount' => 1000, 'reason' => 'Prepaid pack',
        ])->assertSessionHas('status');
        $this->assertSame(1000, $this->balance('purchased'));

        $this->withSession($this->session)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'debit', 'pool' => 'purchased', 'denomination' => 'credit',
            'amount' => 400, 'reason' => 'Correcting a mis-grant',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(600, $this->balance('purchased'));
        $this->assertDatabaseHas('wallet_adjustments', [
            'organization_id' => 'org_w', 'pool_key' => 'purchased', 'amount' => -400, 'direction' => 'debit',
        ]);
    }

    public function test_over_debit_of_a_non_negative_pool_is_refused(): void
    {
        $this->withSession($this->session)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'promotional', 'denomination' => 'credit',
            'amount' => 100, 'reason' => 'Small grant', 'expires_in_days' => 30,
        ])->assertSessionHas('status');

        // Promotional may not go negative — a debit beyond the balance is refused.
        $this->withSession($this->session)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'debit', 'pool' => 'promotional', 'denomination' => 'credit',
            'amount' => 500, 'reason' => 'Too much',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(100, $this->balance('promotional'));
        $this->assertDatabaseMissing('wallet_adjustments', ['organization_id' => 'org_w', 'direction' => 'debit']);
    }

    public function test_the_wallet_panel_renders_on_the_customer_detail(): void
    {
        $this->withSession($this->session)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'promotional', 'denomination' => 'credit',
            'amount' => 750, 'reason' => 'Welcome credit', 'expires_in_days' => 90,
        ]);

        $this->withSession($this->session)->get('/customers/org_w')
            ->assertOk()
            ->assertSee('Wallet &amp; credits', false)
            ->assertSee('promotional')
            ->assertSee('Welcome credit')
            ->assertSee('data-confirm', false);
    }
}
