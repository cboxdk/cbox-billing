<?php

declare(strict_types=1);

namespace App\Billing\Payments\Dunning;

use App\Billing\Payments\AdaptiveRetryStrategy;
use App\Models\DunningStrategy;
use Illuminate\Database\QueryException;

/**
 * Loads the per-category strategy overrides, keyed by category, memoized for the request. A
 * missing table (the migration has not run) or any read error degrades to "no overrides" so
 * the {@see AdaptiveRetryStrategy} still resolves from pure config — the
 * feature never hard-depends on the override table existing.
 */
class DunningStrategyRepository
{
    /** @var array<string, DunningStrategy>|null */
    private ?array $memo = null;

    /** @return array<string, DunningStrategy> */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $rows = DunningStrategy::query()->get();
        } catch (QueryException) {
            return $this->memo = [];
        }

        $byCategory = [];

        foreach ($rows as $row) {
            $byCategory[$row->category] = $row;
        }

        return $this->memo = $byCategory;
    }

    public function forCategory(DeclineCategory $category): ?DunningStrategy
    {
        return $this->all()[$category->value] ?? null;
    }

    /** Drop the memo so a freshly-saved override is seen within the same request. */
    public function flush(): void
    {
        $this->memo = null;
    }
}
