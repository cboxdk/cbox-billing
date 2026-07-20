<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Approvals\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\WalletAdjustment;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The approval engine over the wallet-adjustment path. A grant above the configured credit
 * threshold is HELD (no balance movement) until a DIFFERENT operator approves it, at which point
 * the exact balance change is applied exactly once; below threshold it applies immediately.
 */
class ApprovalWalletConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $maker = ['auth.user' => [
        'sub' => 'demo|maker', 'name' => 'Maker', 'email' => 'maker@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    /** @var array<string, mixed> */
    private array $checker = ['auth.user' => [
        'sub' => 'demo|checker', 'name' => 'Checker', 'email' => 'checker@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-18 10:00:00');
        Organization::query()->create(['id' => 'org_w', 'name' => 'Wallet Co', 'billing_email' => 'w@example.test', 'billing_country' => 'DK', 'billing_currency' => 'DKK']);
    }

    private function requireApprovalAbove(int $thresholdMinor): void
    {
        config()->set('billing.approvals.actions', [
            'wallet.adjust' => ['enabled' => true, 'threshold_minor' => $thresholdMinor, 'required' => 1],
        ]);
    }

    private function balance(string $pool = 'promotional'): int
    {
        return app(Wallet::class)->balance(
            'org_w',
            $pool === 'purchased' ? Pools::purchased() : Pools::promotional(),
            Denomination::unit('credit'),
            (int) (Carbon::now()->getTimestamp() * 1000),
        );
    }

    public function test_grant_below_threshold_applies_immediately(): void
    {
        $this->requireApprovalAbove(10_000);

        $this->withSession($this->maker)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'promotional', 'denomination' => 'credit',
            'amount' => 2500, 'reason' => 'Small goodwill', 'expires_in_days' => 30,
        ])->assertRedirect('/customers/org_w')->assertSessionHas('status');

        $this->assertSame(2500, $this->balance());
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_grant_above_threshold_is_held_then_executed_with_the_exact_balance_change(): void
    {
        $this->requireApprovalAbove(1000);

        // Above the 1 000-credit threshold → held; balance does NOT move yet.
        $this->withSession($this->maker)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'promotional', 'denomination' => 'credit',
            'amount' => 2500, 'reason' => 'Large goodwill', 'expires_in_days' => 30,
        ])->assertRedirect('/customers/org_w')->assertSessionHas('status');

        $this->assertSame(0, $this->balance());
        $this->assertSame(0, WalletAdjustment::query()->count());

        $request = ApprovalRequest::query()->firstOrFail();
        $this->assertSame(ApprovalStatus::Pending, $request->status);
        $this->assertSame(2500, $request->amount_minor);
        $this->assertSame('demo|maker', $request->requested_by_sub);

        // A different operator approves → the grant applies exactly once, exact amount.
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve')
            ->assertRedirect('/approvals')->assertSessionHas('status');

        $this->assertSame(2500, $this->balance());
        $this->assertDatabaseHas('wallet_adjustments', [
            'organization_id' => 'org_w', 'pool_key' => 'promotional', 'amount' => 2500,
            'direction' => 'grant', 'reason' => 'Large goodwill', 'actor' => 'maker@example.test',
        ]);
        $this->assertSame(ApprovalStatus::Executed, $request->refresh()->status);

        // Idempotent: a re-approve does not double the balance.
        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/approve');
        $this->assertSame(2500, $this->balance());
        $this->assertSame(1, WalletAdjustment::query()->count());
    }

    public function test_maker_cannot_approve_their_own_wallet_request(): void
    {
        $this->requireApprovalAbove(1000);

        $this->withSession($this->maker)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'promotional', 'denomination' => 'credit',
            'amount' => 5000, 'reason' => 'Goodwill', 'expires_in_days' => 30,
        ]);
        $request = ApprovalRequest::query()->firstOrFail();

        $this->withSession($this->maker)->post('/approvals/'.$request->id.'/approve')
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, $this->balance());
        $this->assertSame(ApprovalStatus::Pending, $request->refresh()->status);
    }

    public function test_reject_applies_no_balance_change(): void
    {
        $this->requireApprovalAbove(1000);

        $this->withSession($this->maker)->post('/customers/org_w/wallet/adjust', [
            'direction' => 'grant', 'pool' => 'promotional', 'denomination' => 'credit',
            'amount' => 5000, 'reason' => 'Goodwill', 'expires_in_days' => 30,
        ]);
        $request = ApprovalRequest::query()->firstOrFail();

        $this->withSession($this->checker)->post('/approvals/'.$request->id.'/reject', ['note' => 'Too large'])
            ->assertRedirect('/approvals')->assertSessionHas('status');

        $this->assertSame(0, $this->balance());
        $this->assertSame(0, WalletAdjustment::query()->count());
        $this->assertSame(ApprovalStatus::Rejected, $request->refresh()->status);
    }
}
