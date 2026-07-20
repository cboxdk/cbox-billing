<?php

declare(strict_types=1);

namespace App\Billing\Import\Adapters;

use App\Billing\Import\Contracts\SourceAdapter;
use App\Billing\Import\Enums\ImportSource;
use InvalidArgumentException;

/**
 * The registry of every source adapter, keyed by its {@see ImportSource}. Bound as a singleton
 * with the shipped Stripe / Chargebee / Recurly set; a plugin can register another provider's
 * adapter through the container. Deny-by-default: an unregistered source is a hard error.
 */
class AdapterRegistry
{
    /** @var array<string, SourceAdapter> */
    private array $adapters = [];

    /**
     * @param  iterable<SourceAdapter>  $adapters
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(SourceAdapter $adapter): void
    {
        $this->adapters[$adapter->source()->value] = $adapter;
    }

    public function has(ImportSource $source): bool
    {
        return isset($this->adapters[$source->value]);
    }

    public function get(ImportSource $source): SourceAdapter
    {
        return $this->adapters[$source->value]
            ?? throw new InvalidArgumentException("No import adapter registered for [{$source->value}].");
    }

    /**
     * All registered adapters, in registration order.
     *
     * @return list<SourceAdapter>
     */
    public function all(): array
    {
        return array_values($this->adapters);
    }
}
