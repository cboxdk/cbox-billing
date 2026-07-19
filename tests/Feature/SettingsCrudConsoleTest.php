<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Api\Contracts\ApiTokenAuthenticator;
use App\Models\ApiToken;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SellerEntity;
use Database\Seeders\SellerEntitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform-settings authoring (Wave 4): the two DB-backed resources get real CRUD — an API
 * token mints a one-time plaintext over a stored hash and revoke stops it authenticating; a
 * selling entity creates/edits, guards delete behind its invoices (archive instead), and
 * moves the default. The env-driven gateways/webhooks pages render their honest status.
 */
class SettingsCrudConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SellerEntitySeeder::class);
    }

    public function test_the_settings_page_renders_with_actions(): void
    {
        $this->withSession($this->session)->get('/settings')
            ->assertOk()
            ->assertSee('New token')
            ->assertSee('New seller');
    }

    public function test_minting_a_token_returns_a_one_time_plaintext_over_a_stored_hash(): void
    {
        $response = $this->withSession($this->session)->post('/settings/api-tokens', ['name' => 'ci token']);
        $response->assertOk();

        // Rendered directly into the response (SEC-3) — never flashed through the session store.
        $response->assertSessionMissing('minted_token');
        $minted = $response->viewData('minted');
        $this->assertIsArray($minted);
        $plaintext = $minted['plaintext'];
        $this->assertIsString($plaintext);
        $response->assertSee($plaintext, false);

        $token = ApiToken::query()->where('name', 'ci token')->firstOrFail();
        // Only the hash is stored — never the plaintext.
        $this->assertSame(hash('sha256', $plaintext), $token->hash);
        $this->assertNotSame($plaintext, $token->hash);
    }

    public function test_a_minted_token_authenticates_and_a_revoked_token_does_not(): void
    {
        $response = $this->withSession($this->session)->post('/settings/api-tokens', ['name' => 'sdk token']);
        $response->assertOk();
        $minted = $response->viewData('minted');
        $this->assertIsArray($minted);
        $plaintext = $minted['plaintext'];
        $this->assertIsString($plaintext);

        $authenticator = app(ApiTokenAuthenticator::class);
        // The freshly minted token authenticates (operator identity).
        $this->assertNotNull($authenticator->authenticate($plaintext));

        $token = ApiToken::query()->where('name', 'sdk token')->firstOrFail();
        $this->withSession($this->session)->post(route('billing.settings.tokens.revoke', $token->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotNull($token->fresh()?->revoked_at);
        // The revoked token no longer authenticates.
        $this->assertNull($authenticator->authenticate($plaintext));
    }

    public function test_creating_a_seller_persists_it_and_its_registrations(): void
    {
        $this->withSession($this->session)->post('/settings/sellers', [
            'id' => 'acme-uk',
            'legal_name' => 'Acme UK Ltd',
            'registration_number' => 'GB123456789',
            'establishment' => 'gb',
            'currency' => 'gbp',
            'invoice_prefix' => 'ACME-UK',
            'registrations' => [
                ['country' => 'gb', 'number' => 'GB123456789', 'subdivision' => '', 'scheme' => 'standard'],
                ['country' => '', 'number' => ''], // blank row skipped
            ],
        ])->assertRedirect(route('billing.settings', ['tab' => 'sellers']))->assertSessionHas('status');

        $seller = SellerEntity::query()->whereKey('acme-uk')->firstOrFail();
        $this->assertSame('Acme UK Ltd', $seller->legal_name);
        $this->assertSame('GB', $seller->establishment); // upper-cased
        $this->assertSame('GBP', $seller->currency);
        $this->assertSame(1, $seller->taxRegistrations()->count());
    }

    public function test_editing_a_seller_updates_it(): void
    {
        $this->withSession($this->session)->put('/settings/sellers/cbox-dk', [
            'legal_name' => 'Cbox ApS',
            'registration_number' => 'DK99999999',
            'establishment' => 'DK',
            'currency' => 'DKK',
            'invoice_prefix' => 'CBOX-DK',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('Cbox ApS', SellerEntity::query()->whereKey('cbox-dk')->firstOrFail()->legal_name);
    }

    public function test_delete_is_guarded_when_the_seller_has_invoices(): void
    {
        $seller = SellerEntity::query()->create([
            'id' => 'ref-co', 'legal_name' => 'Ref Co', 'registration_number' => 'X1',
            'establishment' => 'DK', 'currency' => 'DKK', 'invoice_prefix' => 'REFCO', 'is_default' => false,
        ]);

        Organization::query()->create(['id' => 'org_ref', 'name' => 'Ref Org', 'billing_currency' => 'DKK']);
        Invoice::query()->create([
            'organization_id' => 'org_ref', 'seller' => 'ref-co', 'number' => 'REFCO-0001', 'currency' => 'DKK', 'status' => 'paid',
        ]);

        $this->withSession($this->session)->delete('/settings/sellers/'.$seller->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        // The guard held — the referenced seller survives, and archives cleanly instead.
        $this->assertDatabaseHas('seller_entities', ['id' => 'ref-co']);
        $this->withSession($this->session)->post('/settings/sellers/'.$seller->id.'/archive')->assertRedirect();
        $this->assertNotNull($seller->fresh()?->archived_at);
    }

    public function test_an_unreferenced_non_default_seller_deletes(): void
    {
        $seller = SellerEntity::query()->create([
            'id' => 'draft-co', 'legal_name' => 'Draft Co', 'registration_number' => 'X2',
            'establishment' => 'DK', 'currency' => 'DKK', 'invoice_prefix' => 'DRAFTX', 'is_default' => false,
        ]);

        $this->withSession($this->session)->delete('/settings/sellers/'.$seller->id)
            ->assertRedirect(route('billing.settings', ['tab' => 'sellers']))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('seller_entities', ['id' => 'draft-co']);
    }

    public function test_the_default_seller_cannot_be_deleted(): void
    {
        $this->withSession($this->session)->delete('/settings/sellers/cbox-dk')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('seller_entities', ['id' => 'cbox-dk']);
    }

    public function test_making_another_seller_the_default_moves_the_flag(): void
    {
        $seller = SellerEntity::query()->create([
            'id' => 'second', 'legal_name' => 'Second Co', 'registration_number' => 'X3',
            'establishment' => 'DK', 'currency' => 'DKK', 'invoice_prefix' => 'SECOND', 'is_default' => false,
        ]);

        $this->withSession($this->session)->post('/settings/sellers/'.$seller->id.'/default')->assertRedirect();

        $this->assertTrue($seller->fresh()?->is_default);
        $this->assertFalse(SellerEntity::query()->whereKey('cbox-dk')->firstOrFail()->is_default);
        // Exactly one default at all times.
        $this->assertSame(1, SellerEntity::query()->where('is_default', true)->count());
    }

    public function test_the_gateways_and_webhooks_status_pages_render(): void
    {
        $this->withSession($this->session)->get('/settings/gateways')
            ->assertOk()
            ->assertSee('Environment keys')
            ->assertSee('CBOX_BILLING_WEBHOOK_SECRET');

        $this->withSession($this->session)->get('/settings/webhooks')
            ->assertOk()
            ->assertSee('Cbox ID provisioning')
            ->assertSee('CBOX_ID_WEBHOOK_SECRET');
    }
}
