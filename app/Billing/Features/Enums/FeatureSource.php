<?php

declare(strict_types=1);

namespace App\Billing\Features\Enums;

/**
 * Where a resolved feature's answer came from, so the console and the API can show the
 * provenance of a grant (and an operator can tell an org-specific override from the plan's
 * baseline).
 *
 *  - {@see Plan}: granted by the org's serving subscription's plan.
 *  - {@see Override}: decided by an org-level override (grant OR revoke) that won over the plan.
 *  - {@see Default}: neither granted it — the deny-by-default answer (`enabled: false`).
 */
enum FeatureSource: string
{
    case Plan = 'plan';
    case Override = 'override';
    case Default = 'default';
}
