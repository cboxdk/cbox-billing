<?php

declare(strict_types=1);

namespace App\Billing\Fx;

use App\Billing\Fx\Contracts\FxRateSource;
use App\Models\FxRate as FxRateModel;
use Throwable;

/**
 * Persists the current rate set from every registered {@see FxRateSource} into `fx_rates`,
 * upserting on (as_of_date, base, quote, source) so a re-run refreshes rather than duplicates.
 * The pull behind the scheduled `fx:refresh` command.
 *
 * Each source is pulled independently: a failure of one (e.g. the ECB fetch is down) is captured
 * and reported, and the others still persist — a transient outage never wipes or blocks the
 * override rates, and the store simply keeps serving the last good rates under the
 * nearest-before as-of policy.
 */
readonly class FxRateRefresher
{
    /**
     * @param  iterable<FxRateSource>  $sources
     */
    public function __construct(private iterable $sources) {}

    /**
     * Pull and persist every source. Returns one {@see FxRefreshResult} per source in the order
     * they were registered.
     *
     * @return list<FxRefreshResult>
     */
    public function refresh(): array
    {
        $results = [];

        foreach ($this->sources as $source) {
            $results[] = $this->refreshSource($source);
        }

        return $results;
    }

    private function refreshSource(FxRateSource $source): FxRefreshResult
    {
        try {
            $rates = $source->rates();
        } catch (Throwable $exception) {
            return FxRefreshResult::failed($source->origin(), $exception->getMessage());
        }

        $rows = [];
        $now = now();

        foreach ($rates as $rate) {
            $rows[] = [
                'as_of_date' => $rate->asOf->toDateString(),
                'base' => $rate->base,
                'quote' => $rate->quote,
                'rate' => (string) $rate->rate,
                'source' => $rate->origin->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            FxRateModel::query()->upsert(
                $rows,
                ['as_of_date', 'base', 'quote', 'source'],
                ['rate', 'updated_at'],
            );
        }

        return FxRefreshResult::ok($source->origin(), count($rows));
    }
}
