<?php

declare(strict_types=1);

namespace App\Billing\Mode\Concerns;

/**
 * BC alias of {@see BelongsToEnvironment}. The plane partition moved from the binary `livemode`
 * boolean to the first-class `environment` key; this thin trait simply composes the generalised
 * one, so the ~30 models that already `use BelongsToMode` gain the environment scope + stamping
 * (and the retained `livemode` mirror) with no churn. Laravel boots the composed trait
 * automatically (its `boot`/`initialize` hooks run via `class_uses_recursive`). New models should
 * `use BelongsToEnvironment` directly.
 */
trait BelongsToMode
{
    use BelongsToEnvironment;
}
