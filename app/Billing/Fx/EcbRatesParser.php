<?php

declare(strict_types=1);

namespace App\Billing\Fx;

use App\Billing\Fx\Enums\FxRateOrigin;
use App\Billing\Fx\ValueObjects\FxRate;
use Carbon\CarbonImmutable;
use DOMDocument;
use RuntimeException;

/**
 * Parses the European Central Bank euro foreign-exchange reference-rate XML
 * (https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml) into typed {@see FxRate}
 * rows, all base EUR. Native DOM parsing — no third-party XML dependency.
 *
 * The feed's shape (namespaced `gesmes`/`eurofxref`):
 *
 *   <Cube>
 *     <Cube time="2026-07-20">
 *       <Cube currency="USD" rate="1.0895"/>
 *       <Cube currency="DKK" rate="7.4604"/>
 *       ...
 *     </Cube>
 *   </Cube>
 *
 * Every `<Cube>` is matched by local name (namespace-agnostic): the one carrying `time` fixes
 * the effective date, and each `currency`/`rate` child becomes `EUR → currency` at the feed's
 * exact published precision (kept as a decimal string — never a float). A malformed or empty
 * document raises rather than silently yielding nothing.
 */
class EcbRatesParser
{
    /**
     * @return list<FxRate>
     */
    public function parse(string $xml): array
    {
        $xml = trim($xml);

        if ($xml === '') {
            throw new RuntimeException('ECB rate feed was empty.');
        }

        $document = new DOMDocument;
        $loaded = @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);

        if ($loaded === false) {
            throw new RuntimeException('ECB rate feed is not well-formed XML.');
        }

        $rates = [];

        foreach ($document->getElementsByTagName('Cube') as $cube) {
            if (! $cube->hasAttribute('time')) {
                continue;
            }

            $date = CarbonImmutable::parse($cube->getAttribute('time'))->startOfDay();

            foreach ($cube->getElementsByTagName('Cube') as $quote) {
                if (! $quote->hasAttribute('currency') || ! $quote->hasAttribute('rate')) {
                    continue;
                }

                $currency = strtoupper(trim($quote->getAttribute('currency')));
                $rate = trim($quote->getAttribute('rate'));

                if ($currency === '' || $rate === '') {
                    continue;
                }

                $rates[] = FxRate::of($date, 'EUR', $currency, $rate, FxRateOrigin::Ecb);
            }
        }

        if ($rates === []) {
            throw new RuntimeException('ECB rate feed contained no dated reference rates.');
        }

        return $rates;
    }
}
