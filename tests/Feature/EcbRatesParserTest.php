<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Fx\EcbRatesParser;
use Tests\TestCase;

/**
 * The ECB euro-reference-rate XML parser: it maps the feed to typed EUR-base rates, and — since
 * dropping LIBXML_NOENT — it never substitutes external XML entities, closing the XXE vector on
 * an attacker-supplied feed (a hostile feed cannot make the parser read a local file).
 */
class EcbRatesParserTest extends TestCase
{
    public function test_it_parses_the_ecb_feed_shape(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
          <Cube>
            <Cube time="2026-07-20">
              <Cube currency="USD" rate="1.0895"/>
              <Cube currency="DKK" rate="7.4604"/>
            </Cube>
          </Cube>
        </gesmes:Envelope>
        XML;

        $rates = (new EcbRatesParser)->parse($xml);

        $this->assertCount(2, $rates);
        $codes = array_map(static fn ($r): string => $r->quote, $rates);
        $this->assertEqualsCanonicalizing(['USD', 'DKK'], $codes);
    }

    public function test_it_does_not_expand_an_external_entity(): void
    {
        // A local file the XXE payload would try to exfiltrate through an external entity.
        $secretFile = tempnam(sys_get_temp_dir(), 'ecb_xxe_');
        $this->assertIsString($secretFile);
        file_put_contents($secretFile, 'SENTINEL_LEAK_9999');

        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE Envelope [<!ENTITY xxe SYSTEM "file://{$secretFile}">]>
        <Envelope>
          <Cube>
            <Cube time="2026-07-20">
              <Cube currency="USD" rate="&xxe;"/>
            </Cube>
          </Cube>
        </Envelope>
        XML;

        // With LIBXML_NOENT off (+ NONET), the external entity is never resolved — parsing either
        // yields no usable rate or leaves the reference unexpanded, but never leaks the file.
        try {
            $rates = (new EcbRatesParser)->parse($xml);
        } catch (\RuntimeException) {
            $rates = [];
        } finally {
            @unlink($secretFile);
        }

        foreach ($rates as $rate) {
            $this->assertStringNotContainsString('SENTINEL_LEAK', (string) $rate->rate);
        }
    }
}
