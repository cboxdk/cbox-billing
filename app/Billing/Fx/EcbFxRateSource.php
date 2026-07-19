<?php

declare(strict_types=1);

namespace App\Billing\Fx;

use App\Billing\Fx\Contracts\FxRateSource;
use App\Billing\Fx\Enums\FxRateOrigin;
use App\Billing\Fx\ValueObjects\FxRate;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * The real ECB feed adapter — the citable, free, public source of truth for consolidated
 * reporting. Fetches the European Central Bank euro foreign-exchange reference rates and hands
 * the body to {@see EcbRatesParser}, yielding base-EUR {@see FxRate}
 * rows. Non-EUR pairs (e.g. DKK → USD) are NOT stored here; they are derived at read time via
 * the EUR pivot in {@see FxRateRepository}, so the store keeps exactly what ECB publishes and
 * nothing invented.
 *
 * Source: European Central Bank, "Euro foreign exchange reference rates" —
 * https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml (see docs/reporting/fx-rates.md).
 * The rates are published on TARGET business days around 16:00 CET; a pull on a weekend/holiday
 * returns the most recent business-day set, which the nearest-before as-of policy handles.
 */
readonly class EcbFxRateSource implements FxRateSource
{
    public function __construct(
        private HttpFactory $http,
        private EcbRatesParser $parser,
        private string $url,
    ) {}

    public function origin(): FxRateOrigin
    {
        return FxRateOrigin::Ecb;
    }

    public function rates(): array
    {
        $body = $this->http
            ->accept('application/xml')
            ->timeout(15)
            ->retry(2, 250)
            ->get($this->url)
            ->throw()
            ->body();

        return $this->parser->parse($body);
    }
}
