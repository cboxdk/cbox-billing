<?php

declare(strict_types=1);

namespace App\Billing\Metering;

use App\Models\Meter;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;

/**
 * Projects an org's resolved per-meter policies into the flat shape the SDK caches to
 * enforce locally — the `/entitlements/{org}` payload. Reads through the same
 * {@see MeterPolicyResolver} the enforcer uses, so what the SDK sees is exactly what the
 * server would decide. Deny-by-default: a meter with no resolved policy is reported
 * `enabled: false` rather than omitted.
 */
readonly class EntitlementsView
{
    public function __construct(private MeterPolicyResolver $policies) {}

    /**
     * @return array<string, array{enabled: bool, allowance: int|null, weight: float|null, overage: string}>
     */
    public function forOrganization(string $org): array
    {
        $meters = [];

        foreach (Meter::query()->orderBy('key')->get() as $meter) {
            $policy = $this->policies->resolve($org, $meter->key);

            if ($policy === null || ! $policy->enabled) {
                $meters[$meter->key] = [
                    'enabled' => false,
                    'allowance' => null,
                    'weight' => null,
                    'overage' => 'block',
                ];

                continue;
            }

            $meters[$meter->key] = [
                'enabled' => true,
                'allowance' => $policy->unlimited ? null : $policy->allowance,
                'weight' => $policy->multiplier,
                'overage' => $policy->overage->value,
            ];
        }

        return $meters;
    }
}
