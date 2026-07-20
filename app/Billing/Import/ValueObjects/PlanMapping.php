<?php

declare(strict_types=1);

namespace App\Billing\Import\ValueObjects;

/**
 * The operator's source-plan → app-plan routing. Catalog modelling differs between providers, so
 * a source plan may need to be pointed at an EXISTING app plan rather than imported as a new one.
 * This map carries those explicit overrides (source plan id → app plan id); a source plan with no
 * entry falls to auto-matching (by natural key) and then to creation, and a subscription whose
 * plan can be resolved neither by this map, an import, nor an existing app plan is flagged as a
 * conflict — never invented.
 */
readonly class PlanMapping
{
    /**
     * @param  array<string, string>  $map  source plan id → app plan id (as a string)
     */
    public function __construct(private array $map = []) {}

    /**
     * Build from request/stored input, discarding empty targets so a blank form field is treated
     * as "no override" rather than a mapping to nothing.
     *
     * @param  array<int|string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $map = [];

        foreach ($raw as $sourceId => $appId) {
            $sourceId = (string) $sourceId;
            $appId = is_scalar($appId) ? trim((string) $appId) : '';

            if ($sourceId !== '' && $appId !== '') {
                $map[$sourceId] = $appId;
            }
        }

        return new self($map);
    }

    /** The app plan id a source plan is explicitly routed to, or null when unmapped. */
    public function for(string $sourcePlanId): ?string
    {
        return $this->map[$sourcePlanId] ?? null;
    }

    public function has(string $sourcePlanId): bool
    {
        return isset($this->map[$sourcePlanId]);
    }

    public function isEmpty(): bool
    {
        return $this->map === [];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->map;
    }
}
