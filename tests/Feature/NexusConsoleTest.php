<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SellerEntity;
use App\Models\SellerExternalSales;
use App\Models\SellerPhysicalPresence;
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
}
