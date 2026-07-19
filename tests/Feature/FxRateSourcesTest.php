<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Fx\FxRateRefresher;
use App\Billing\Fx\FxRateRepository;
use App\Models\FxRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The FX rate sources and the refresh pull: the ECB feed adapter ingests the real feed shape
 * over a faked HTTP response, the operator-override source reads config, an override supersedes
 * ECB on the same date/pair, and the console FX admin renders + authors an override. No rate is
 * ever fabricated.
 */
class FxRateSourcesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|tester', 'name' => 'Test Operator', 'email' => 'ops@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    private const ECB_XML = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
        .'<Cube><Cube time="2026-07-17">'
        .'<Cube currency="USD" rate="1.0895"/><Cube currency="DKK" rate="7.4604"/>'
        .'</Cube></Cube></gesmes:Envelope>';

    public function test_refresh_ingests_ecb_feed_and_operator_overrides(): void
    {
        Http::fake(['*ecb.europa.eu*' => Http::response(self::ECB_XML, 200)]);
        config()->set('billing.fx.sources', ['ecb', 'override']);
        config()->set('billing.fx.overrides', [
            ['date' => '2026-07-17', 'base' => 'USD', 'quote' => 'XOF', 'rate' => '600.0'],
        ]);

        $results = app(FxRateRefresher::class)->refresh();

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->ok);
        $this->assertSame(2, $results[0]->count); // USD + DKK from ECB
        $this->assertSame(1, $results[1]->count); // the override

        $this->assertDatabaseHas('fx_rates', ['base' => 'EUR', 'quote' => 'DKK', 'source' => 'ecb']);
        $this->assertDatabaseHas('fx_rates', ['base' => 'USD', 'quote' => 'XOF', 'source' => 'override']);

        // The override pair (ECB doesn't publish XOF) resolves from the override row.
        $rate = app(FxRateRepository::class)->effectiveRate('USD', 'XOF', CarbonImmutable::parse('2026-07-18'));
        $this->assertNotNull($rate);
        $this->assertSame('override', $rate->origin->value);
    }

    public function test_an_override_supersedes_ecb_on_the_same_date_and_pair(): void
    {
        FxRate::query()->create(['as_of_date' => '2026-07-17', 'base' => 'EUR', 'quote' => 'DKK', 'rate' => '7.4604', 'source' => 'ecb']);
        FxRate::query()->create(['as_of_date' => '2026-07-17', 'base' => 'EUR', 'quote' => 'DKK', 'rate' => '7.5000', 'source' => 'override']);

        $rate = app(FxRateRepository::class)->storedRate('EUR', 'DKK', CarbonImmutable::parse('2026-07-18'));

        $this->assertNotNull($rate);
        $this->assertSame('override', $rate->origin->value);
        $this->assertSame('7.500000000000', (string) $rate->rate);
    }

    public function test_refresh_reports_a_source_failure_without_blocking_the_others(): void
    {
        Http::fake(['*ecb.europa.eu*' => Http::response('boom', 500)]);
        config()->set('billing.fx.sources', ['ecb', 'override']);
        config()->set('billing.fx.overrides', [
            ['base' => 'USD', 'quote' => 'XOF', 'rate' => '600.0', 'date' => '2026-07-17'],
        ]);

        $results = app(FxRateRefresher::class)->refresh();

        $this->assertFalse($results[0]->ok);      // ECB failed
        $this->assertNotNull($results[0]->error);
        $this->assertTrue($results[1]->ok);        // override still persisted
        $this->assertDatabaseHas('fx_rates', ['quote' => 'XOF', 'source' => 'override']);
    }

    public function test_fx_admin_page_renders_and_stores_an_override(): void
    {
        FxRate::query()->create(['as_of_date' => '2026-07-17', 'base' => 'EUR', 'quote' => 'DKK', 'rate' => '7.4604', 'source' => 'ecb']);

        $this->withSession($this->session)->get('/settings/fx')
            ->assertOk()
            ->assertSee('FX rates')
            ->assertSee('ECB');

        $this->withSession($this->session)->post('/settings/fx/overrides', [
            'base' => 'usd', 'quote' => 'xof', 'rate' => '600.0', 'as_of_date' => '2026-07-17',
        ])->assertRedirect(route('billing.settings.fx'));

        $this->assertDatabaseHas('fx_rates', [
            'base' => 'USD', 'quote' => 'XOF', 'source' => 'override',
        ]);
    }
}
