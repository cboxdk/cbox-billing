<?php

declare(strict_types=1);

namespace App\Billing\Features\ValueObjects;

use App\Billing\Features\Enums\FeatureSource;
use App\Billing\Features\Enums\FeatureType;

/**
 * The resolved answer for one feature and one org: whether it is granted, its typed config value
 * (null for a boolean feature or an ungranted one), and where the answer came from (plan grant,
 * org override, or the deny-by-default baseline). This is what the `/features` API and the
 * console render — a typed value object, never a loose array threaded through the domain.
 */
readonly class ResolvedFeature
{
    public function __construct(
        public string $key,
        public ?FeatureType $type,
        public bool $enabled,
        public int|string|null $value,
        public FeatureSource $source,
    ) {}

    /** The deny-by-default answer for a feature: not granted, no value, from the baseline. */
    public static function denied(string $key, ?FeatureType $type = null): self
    {
        return new self($key, $type, false, null, FeatureSource::Default);
    }

    /**
     * The serialization-boundary shape (the HTTP body). `type` is null only for an unknown
     * feature (a single check against a key that is not in the catalog).
     *
     * @return array{type: string|null, enabled: bool, value: int|string|null, source: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type?->value,
            'enabled' => $this->enabled,
            'value' => $this->value,
            'source' => $this->source->value,
        ];
    }
}
