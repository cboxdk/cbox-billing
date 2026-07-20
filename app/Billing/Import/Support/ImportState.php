<?php

declare(strict_types=1);

namespace App\Billing\Import\Support;

use App\Billing\Import\BillingImporter;
use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\ValueObjects\PlannedAction;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;

/**
 * The mutable working state threaded through one import walk (dry-run or commit). It accumulates
 * the resolved source→app mappings each stage needs (a subscription needs its customer's org and
 * its plan; a price needs its plan) and the running list of {@see PlannedAction}s. It is internal
 * to the {@see BillingImporter} — not a domain value object — hence mutable.
 */
class ImportState
{
    /** @var list<PlannedAction> */
    public array $actions = [];

    /** @var array<string, string> source product id → app product id */
    public array $productApp = [];

    /** @var array<string, string> source plan id → app plan id (a `(new)` sentinel while planning a would-create) */
    public array $planApp = [];

    /** @var array<string, Plan> source plan id → app plan model (commit only) */
    public array $planModel = [];

    /** @var array<string, string> source plan id → the currency it prices in */
    public array $planCurrency = [];

    /** @var array<string, string> source customer id → app organization id */
    public array $orgApp = [];

    /** @var array<string, Organization> source customer id → app organization model (commit only) */
    public array $orgModel = [];

    /** @var array<string, Coupon> coupon code → app coupon model (commit only) */
    public array $couponModel = [];

    public function __construct(
        public readonly bool $commit,
        public readonly ImportSource $source,
        public readonly ?int $runId = null,
    ) {}

    public function push(PlannedAction $action): void
    {
        $this->actions[] = $action;
    }
}
