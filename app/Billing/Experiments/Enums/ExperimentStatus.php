<?php

declare(strict_types=1);

namespace App\Billing\Experiments\Enums;

/**
 * The lifecycle of a pricing {@see App\Models\Experiment}. Only a `Running` experiment does
 * per-visitor variant assignment on the public storefront and accrues impressions/conversions;
 * a `Draft` is still being configured (its `/pricing/{key}` serves the plain base table), and a
 * `Concluded` experiment is over — it stops assigning, and if a winner was promoted the base
 * page serves that winner's table instead (see {@see App\Billing\Experiments\StorefrontExperimentResolver}).
 */
enum ExperimentStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Concluded = 'concluded';

    /** Whether this status serves per-visitor variants (only a running experiment does). */
    public function isServing(): bool
    {
        return $this === self::Running;
    }

    /** A short human label for the console badge. */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The design-system pill tone for the console badge. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Running => 'success',
            self::Concluded => 'info',
        };
    }
}
