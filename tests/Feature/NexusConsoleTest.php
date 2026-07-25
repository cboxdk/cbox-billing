<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SellerEntity;
use App\Models\SellerExternalSales;
use App\Models\SellerPhysicalPresence;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Nexus\Contracts\NexusThresholdSource;
use Cbox\Nexus\ValueObjects\EconomicNexusThreshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The US economic-nexus console: the per-state standing plus the operator-declared
 * physical-presence and external-channel-sales registers (add/remove), gated nexus:read/manage.
 */
class NexusConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $permissions */
    private function signedInWith(array $permissions = ['nexus:read', 'nexus:manage']): self
    {
        $this->withSession(['auth.user' => [
            'sub' => 'demo|operator', 'name' => 'Test Operator', 'email' => 'ops@example.test',
            'org' => 'org_hverdag', 'picture' => null, 'permissions' => $permissions,
        ]]);

        return $this;
    }

    private function defaultSeller(): SellerEntity
    {
        return SellerEntity::query()->create([
            'id' => 'us-co', 'legal_name' => 'US Co', 'registration_number' => 'US-0001',
            'establishment' => 'US', 'currency' => 'USD', 'invoice_prefix' => 'USCO', 'is_default' => true,
        ]);
    }

    public function test_index_renders_the_registers(): void
    {
        $this->defaultSeller();

        $this->signedInWith()->get('/nexus')
            ->assertOk()
            ->assertSee('US economic nexus')
            ->assertSee('Physical presence')
            ->assertSee('External-channel sales');
    }

    public function test_declare_and_remove_physical_presence(): void
    {
        $this->defaultSeller();

        $this->signedInWith()->post('/nexus/presence', [
            'subdivision' => 'US-CA', 'effective_from' => '2026-01-01',
        ])->assertRedirect(route('billing.nexus'));

        $presence = SellerPhysicalPresence::query()->where('subdivision', 'US-CA')->firstOrFail();
        $this->assertSame('us-co', $presence->seller_entity_id);
        $this->assertSame('2026-01-01', $presence->effective_from?->format('Y-m-d'));

        // It shows on the page, then is removable.
        $this->signedInWith()->get('/nexus')->assertOk()->assertSee('California');

        $this->signedInWith()->delete("/nexus/presence/{$presence->id}")->assertRedirect(route('billing.nexus'));
        $this->assertSame(0, SellerPhysicalPresence::query()->count());
    }

    public function test_record_and_remove_external_channel_sales(): void
    {
        $this->defaultSeller();

        $this->signedInWith()->post('/nexus/external-sales', [
            'subdivision' => 'US-TX', 'period_year' => 2026,
            'sales_dollars' => 250000, 'transactions' => 40, 'source' => 'Amazon Marketplace',
        ])->assertRedirect(route('billing.nexus'));

        $entry = SellerExternalSales::query()->where('subdivision', 'US-TX')->firstOrFail();
        $this->assertSame('us-co', $entry->seller_entity_id);
        $this->assertSame(250000, $entry->sales_dollars);
        $this->assertSame('Amazon Marketplace', $entry->source);

        $this->signedInWith()->delete("/nexus/external-sales/{$entry->id}")->assertRedirect(route('billing.nexus'));
        $this->assertSame(0, SellerExternalSales::query()->count());
    }

    public function test_presence_rejects_an_unknown_state(): void
    {
        $this->defaultSeller();

        $this->signedInWith()->post('/nexus/presence', ['subdivision' => 'US-ZZ'])
            ->assertSessionHasErrors('subdivision');
        $this->assertSame(0, SellerPhysicalPresence::query()->count());
    }

    /**
     * A state whose threshold could not be resolved has NO standing — the engine has no opinion.
     * Rendering it alongside genuine "below threshold" rows reads as compliance, and that is the
     * failure a self-hosted deployment actually hits: the threshold dataset is fetched over the
     * network, so a firewalled install resolves null for EVERY state and shows a clean board while
     * the seller crosses thresholds nationwide.
     */
    public function test_a_state_with_no_resolvable_threshold_is_surfaced_not_shown_as_below(): void
    {
        $seller = $this->defaultSeller();

        // Real activity in a state, so it is actually evaluated — the failure being guarded is a
        // state that HAS sales but whose threshold cannot be resolved.
        SellerExternalSales::query()->create([
            'seller_entity_id' => $seller->id, 'subdivision' => 'US-CA', 'channel' => 'marketplace',
            'period_year' => now()->year, 'sales_dollars' => 250_000, 'transactions' => 400,
        ]);

        // A threshold source that resolves nothing — exactly what a firewalled deployment gets.
        $this->app->bind(NexusThresholdSource::class, fn (): NexusThresholdSource => new class implements NexusThresholdSource
        {
            public function thresholdFor(SubdivisionCode $state): ?EconomicNexusThreshold
            {
                return null;
            }
        });

        $response = $this->signedInWith()->get('/nexus');

        $response->assertOk();
        $response->assertSee('threshold unknown', false);
        $response->assertSee('not below', false);
    }
}
