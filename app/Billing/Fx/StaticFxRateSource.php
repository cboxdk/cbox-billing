<?php

declare(strict_types=1);

namespace App\Billing\Fx;

use App\Billing\Fx\Contracts\FxRateSource;
use App\Billing\Fx\Enums\FxRateOrigin;
use App\Billing\Fx\ValueObjects\FxRate;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The operator/treasury override source: directed rates an operator fixes by hand for a pair
 * ECB does not publish (an exotic currency, an internal settlement rate) or to pin a
 * treasury-agreed rate over the market one. Read from `billing.fx.overrides`, each entry a
 * `{date?, base, quote, rate}` map — `date` defaults to today when omitted. An override
 * supersedes ECB on the same (date, pair) at resolution time.
 *
 * This never fabricates: it emits only what an operator explicitly authored. A malformed entry
 * (missing base/quote/rate, or a non-numeric rate) is skipped rather than guessed, so a typo can
 * never masquerade as a real rate.
 */
readonly class StaticFxRateSource implements FxRateSource
{
    public function __construct(private Config $config) {}

    public function origin(): FxRateOrigin
    {
        return FxRateOrigin::Override;
    }

    public function rates(): array
    {
        $configured = $this->config->get('billing.fx.overrides', []);

        if (! is_array($configured)) {
            return [];
        }

        $rates = [];

        foreach ($configured as $entry) {
            $rate = $this->parse($entry);

            if ($rate !== null) {
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /**
     * Parse one config entry into a typed {@see FxRate}, or null when it is not a complete,
     * numeric directed quote (skipped, never guessed).
     *
     * @param  mixed  $entry
     */
    private function parse($entry): ?FxRate
    {
        if (! is_array($entry)) {
            return null;
        }

        $base = $entry['base'] ?? null;
        $quote = $entry['quote'] ?? null;
        $rate = $entry['rate'] ?? null;

        if (! is_string($base) || ! is_string($quote) || ! (is_string($rate) || is_int($rate) || is_float($rate))) {
            return null;
        }

        $base = strtoupper(trim($base));
        $quote = strtoupper(trim($quote));

        if (strlen($base) !== 3 || strlen($quote) !== 3) {
            return null;
        }

        $date = $entry['date'] ?? null;
        $asOf = is_string($date) && $date !== ''
            ? CarbonImmutable::parse($date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        try {
            return FxRate::of($asOf, $base, $quote, (string) $rate, FxRateOrigin::Override);
        } catch (MathException) {
            return null;
        }
    }
}
