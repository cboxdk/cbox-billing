<?php

declare(strict_types=1);

namespace App\Billing\Features\Enums;

use App\Models\Feature;

/**
 * What shape of grant a {@see Feature} carries.
 *
 *  - {@see Boolean}: a pure on/off capability — the org either has it or it doesn't
 *    (`sso`, `custom_domains`, `priority_support`). This is the classic feature-flag grant.
 *  - {@see Config}: a capability that carries a typed value/limit (`max_projects=10`,
 *    `support_tier=gold`). Its value is stored as a string and typed on resolution by the
 *    feature's {@see ConfigValueType}.
 */
enum FeatureType: string
{
    case Boolean = 'boolean';
    case Config = 'config';

    /** Whether this feature carries a typed value (config) rather than a bare boolean. */
    public function carriesValue(): bool
    {
        return $this === self::Config;
    }

    /** A short human label for the console. */
    public function label(): string
    {
        return match ($this) {
            self::Boolean => 'Boolean',
            self::Config => 'Config',
        };
    }
}
