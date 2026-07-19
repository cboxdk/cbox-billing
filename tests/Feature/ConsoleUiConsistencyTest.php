<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Database\Seeders\OrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the UI/UX consistency fixes: detail-page back buttons use the left-pointing
 * glyph (never the forward chevron), and the Settings subnav "deep links" resolve to real
 * on-page fragment anchors so they actually jump to the section.
 */
class ConsoleUiConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CatalogSeeder::class, OrganizationSeeder::class]);
    }

    public function test_detail_back_button_uses_the_left_chevron_glyph(): void
    {
        $response = $this->withSession($this->session)->get('/customers/org_hverdag')->assertOk();

        // The <x-back-button> renders the back-pointing chevron-left path…
        $response->assertSee('Back to customers');
        $response->assertSee('m15 18-6-6 6-6', false);
    }

    public function test_settings_subnav_links_carry_a_real_fragment_anchor(): void
    {
        // The subnav renders on every console page; its Settings deep-links must end in the
        // matching on-page anchor (e.g. #sellers), not merely set ?tab=.
        $response = $this->withSession($this->session)->get('/settings')->assertOk();

        $response->assertSee('tab=sellers#sellers', false);
        $response->assertSee('tab=tokens#tokens', false);
        // And the section anchors those links target really exist on the page.
        $response->assertSee('id="sellers"', false);
        $response->assertSee('id="tokens"', false);
    }
}
