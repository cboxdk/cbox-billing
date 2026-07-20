<?php

declare(strict_types=1);

namespace App\Billing\Import;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Catalog\Contracts\AuthorsPlanPrices;
use App\Billing\Catalog\PlanAuthoring;
use App\Billing\Catalog\ProductAuthoring;
use App\Billing\Catalog\ValueObjects\PlanPriceDraft;
use App\Billing\Coupons\CouponAuthoring;
use App\Billing\Coupons\CouponRedeemer;
use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Coupons\Enums\CouponDuration;
use App\Billing\Coupons\Enums\CouponScope;
use App\Billing\Coupons\ValueObjects\CouponDraft;
use App\Billing\Import\Enums\ImportEntityType;
use App\Billing\Import\Enums\ImportOutcome;
use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\Normalized\NormalizedCoupon;
use App\Billing\Import\Normalized\NormalizedCustomer;
use App\Billing\Import\Normalized\NormalizedDataset;
use App\Billing\Import\Normalized\NormalizedInvoice;
use App\Billing\Import\Normalized\NormalizedPlan;
use App\Billing\Import\Normalized\NormalizedPrice;
use App\Billing\Import\Normalized\NormalizedProduct;
use App\Billing\Import\Normalized\NormalizedSubscription;
use App\Billing\Import\Support\ImportState;
use App\Billing\Import\ValueObjects\ImportPlan;
use App\Billing\Import\ValueObjects\PlanMapping;
use App\Billing\Import\ValueObjects\PlannedAction;
use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Mode\BillingContext;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Coupon;
use App\Models\ImportRun;
use App\Models\ImportRunEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionMrrMovement;
use Carbon\CarbonImmutable;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The import pipeline. It plans (dry-run) and commits an already-parsed {@see NormalizedDataset}
 * into the app through the REAL domain services — never a raw insert of a live catalog/customer
 * record — in dependency order, keyed idempotently on the {@see ImportSourceRefStore} ledger.
 *
 * The same walk backs both {@see Plan()} and {@see commit()} (an `$commit` flag gates the writes),
 * so a dry-run reports exactly what the commit will do. Idempotency: a source record already in
 * the ledger is Skipped; a record whose app twin already exists by natural key (a product/plan/
 * coupon key, a customer email) is linked or flagged rather than duplicated. Historical dates
 * (org signup, subscription period anchors + creation, the MRR-movement timing, invoice dates)
 * are preserved so cohorts and MRR history line up after the cut-over.
 *
 * Historical invoices are the one deliberate exception to "go through the issuer": they are
 * imported as FAITHFUL RECORDS (their original number, amounts, tax and dates preserved) under a
 * namespaced pseudo-seller — re-issuing them through the engine would assign fresh legal numbers
 * and recompute tax, destroying the historical record. New invoices are issued normally.
 */
readonly class BillingImporter
{
    public function __construct(
        private ImportSourceRefStore $refs,
        private ProductAuthoring $products,
        private PlanAuthoring $plans,
        private AuthorsPlanPrices $prices,
        private CouponAuthoring $coupons,
        private CouponRedeemer $redeemer,
        private SubscribesOrganizations $subscriptions,
        private RecordsAudit $audit,
        private BillingContext $context,
    ) {}

    /**
     * The DRY RUN: resolve the whole export against the ledger and surface every planned action
     * (created / updated / skipped) plus conflicts (an unmapped plan, a duplicate email, an
     * unsupported currency/interval) WITHOUT writing anything.
     */
    public function plan(ImportSource $source, NormalizedDataset $data, PlanMapping $mapping): ImportPlan
    {
        $state = new ImportState(commit: false, source: $source);
        $this->walk($state, $data, $mapping);

        return new ImportPlan($state->actions);
    }

    /**
     * Execute the import for real, recording per-row log entries + idempotency refs against the
     * run, then stamp the run committed with its counts. Re-runnable: already-imported rows are
     * skipped, so a resumed/repeated commit never duplicates.
     */
    public function commit(ImportRun $run, NormalizedDataset $data, PlanMapping $mapping): ImportPlan
    {
        $source = ImportSource::from($run->source);
        $state = new ImportState(commit: true, source: $source, runId: $run->id);

        $run->forceFill(['status' => 'running'])->save();

        $this->walk($state, $data, $mapping);

        $plan = new ImportPlan($state->actions);

        $run->forceFill([
            'status' => 'completed',
            'dry_run' => false,
            'counts' => $plan->counts(),
            'conflicts' => $plan->conflictsForStorage(),
            'committed_at' => Carbon::now(),
        ])->save();

        $this->audit->record(
            AuditAction::DataImported,
            AuditTarget::of('import_run', (string) $run->id),
            sprintf('Imported %s data (run #%d) into %s mode.', $source->label(), $run->id, $run->livemode ? 'live' : 'test'),
            ['source' => $source->value, 'counts' => $plan->counts(), 'livemode' => $run->livemode],
        );

        return $plan;
    }

    /** Walk every entity in dependency order. */
    private function walk(ImportState $state, NormalizedDataset $data, PlanMapping $mapping): void
    {
        // Preload the whole idempotency ledger once, so each row's "already imported?" check is a
        // memory lookup, not a query per record (the dry-run + commit N+1). Refs recorded during a
        // commit walk are threaded through the per-entity state maps, so a walk-start snapshot is
        // always the correct baseline.
        $state->knownRefs = $this->refs->snapshot($state->source);

        foreach ($data->products as $product) {
            $this->product($state, $product);
        }

        foreach ($data->plans as $plan) {
            $this->plan_($state, $data, $mapping, $plan);
        }

        foreach ($data->prices as $price) {
            $this->price($state, $price);
        }

        foreach ($data->coupons as $coupon) {
            $this->coupon($state, $coupon);
        }

        foreach ($data->customers as $customer) {
            $this->customer($state, $customer);
        }

        foreach ($data->subscriptions as $subscription) {
            $this->subscription($state, $mapping, $subscription);
        }

        foreach ($data->invoices as $invoice) {
            $this->invoice($state, $invoice);
        }
    }

    // --- Products -----------------------------------------------------------------------------

    private function product(ImportState $state, NormalizedProduct $p): void
    {
        $base = new PlannedAction(ImportEntityType::Product, $p->sourceId, $p->name, ImportOutcome::Created);

        $ref = $state->refFor(ImportEntityType::Product, $p->sourceId);
        if ($ref !== null) {
            $state->productApp[$p->sourceId] = $ref->app_id;
            $this->emit($state, $base->withOutcome(ImportOutcome::Skipped, 'Already imported.')->resolvedTo('product', $ref->app_id));

            return;
        }

        $existing = Product::query()->where('key', $p->key)->first();
        if ($existing instanceof Product) {
            $state->productApp[$p->sourceId] = (string) $existing->id;
            if ($state->commit) {
                $this->refs->record($state->source, ImportEntityType::Product, $p->sourceId, 'product', (string) $existing->id, $state->runId);
            }
            $this->emit($state, $base->withOutcome(ImportOutcome::Updated, 'Linked to existing product by key.')->resolvedTo('product', (string) $existing->id));

            return;
        }

        if (! $state->commit) {
            $state->productApp[$p->sourceId] = '(new)';
            $this->emit($state, $base);

            return;
        }

        try {
            $product = $this->products->create(['key' => $p->key, 'name' => $p->name, 'description' => $p->description]);
        } catch (Throwable $e) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, $e->getMessage()));

            return;
        }

        $this->backdate($product, $p->createdAt);
        $state->productApp[$p->sourceId] = (string) $product->id;
        $this->refs->record($state->source, ImportEntityType::Product, $p->sourceId, 'product', (string) $product->id, $state->runId);
        $this->emit($state, $base->resolvedTo('product', (string) $product->id));
    }

    // --- Plans --------------------------------------------------------------------------------

    private function plan_(ImportState $state, NormalizedDataset $data, PlanMapping $mapping, NormalizedPlan $p): void
    {
        $base = new PlannedAction(ImportEntityType::Plan, $p->sourceId, $p->name, ImportOutcome::Created);
        $price = $data->priceForPlan($p->sourceId);

        // 1. An explicit operator mapping routes the source plan onto a chosen app plan.
        $mapped = $mapping->for($p->sourceId);
        if ($mapped !== null) {
            $appPlan = Plan::query()->whereKey($mapped)->first();
            if (! $appPlan instanceof Plan) {
                $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, "Mapped app plan [{$mapped}] does not exist."));

                return;
            }
            $this->linkPlan($state, $p, $appPlan, $price, 'Routed to app plan by operator mapping.');

            return;
        }

        // 2. Already imported.
        $ref = $state->refFor(ImportEntityType::Plan, $p->sourceId);
        if ($ref !== null) {
            $appPlan = Plan::query()->whereKey($ref->app_id)->first();
            if ($appPlan instanceof Plan) {
                $state->planApp[$p->sourceId] = $ref->app_id;
                $state->planModel[$p->sourceId] = $appPlan;
                if ($price?->currency !== null) {
                    $state->planCurrency[$p->sourceId] = $price->currency;
                }
                $this->emit($state, $base->withOutcome(ImportOutcome::Skipped, 'Already imported.')->resolvedTo('plan', $ref->app_id));

                return;
            }
        }

        // 3. An existing app plan with the same natural key — link rather than duplicate.
        $existing = Plan::query()->where('key', $p->key)->first();
        if ($existing instanceof Plan) {
            $this->linkPlan($state, $p, $existing, $price, 'Linked to existing plan by key.');

            return;
        }

        // 4. Create — but only if the plan is billable and priceable. Otherwise flag, never invent.
        if ($p->interval === null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, "Unsupported billing interval '{$p->rawInterval}' (only monthly/yearly bill)."));

            return;
        }

        if ($price === null || $price->currency === null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, 'No price/currency for this plan — cannot create a priceable plan.'));

            return;
        }

        if (! $state->commit) {
            $state->planApp[$p->sourceId] = '(new)';
            $state->planCurrency[$p->sourceId] = $price->currency;
            $this->emit($state, $base);

            return;
        }

        try {
            $plan = $this->plans->create([
                'product_id' => (int) $this->productIdFor($state, $p),
                'key' => $p->key,
                'name' => $p->name,
                'interval' => $p->interval->value,
                'active' => true,
            ]);
        } catch (Throwable $e) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, $e->getMessage()));

            return;
        }

        $this->backdate($plan, $p->createdAt);
        $state->planApp[$p->sourceId] = (string) $plan->id;
        $state->planModel[$p->sourceId] = $plan;
        $state->planCurrency[$p->sourceId] = $price->currency;
        $this->refs->record($state->source, ImportEntityType::Plan, $p->sourceId, 'plan', (string) $plan->id, $state->runId);
        $this->emit($state, $base->resolvedTo('plan', (string) $plan->id));
    }

    private function linkPlan(ImportState $state, NormalizedPlan $p, Plan $appPlan, ?NormalizedPrice $price, string $reason): void
    {
        $state->planApp[$p->sourceId] = (string) $appPlan->id;
        $state->planModel[$p->sourceId] = $appPlan;
        if ($price?->currency !== null) {
            $state->planCurrency[$p->sourceId] = $price->currency;
        }

        if ($state->commit) {
            $this->refs->record($state->source, ImportEntityType::Plan, $p->sourceId, 'plan', (string) $appPlan->id, $state->runId);
        }

        $this->emit($state, (new PlannedAction(ImportEntityType::Plan, $p->sourceId, $p->name, ImportOutcome::Updated, 'plan', (string) $appPlan->id, $reason)));
    }

    /** The app product id a plan hangs under — its mapped product, or a synthetic per-source default. */
    private function productIdFor(ImportState $state, NormalizedPlan $p): string
    {
        if ($p->productSourceId !== null && isset($state->productApp[$p->productSourceId]) && $state->productApp[$p->productSourceId] !== '(new)') {
            return $state->productApp[$p->productSourceId];
        }

        if (isset($state->productApp['__default__'])) {
            return $state->productApp['__default__'];
        }

        $key = 'imported-'.$state->source->value;
        $product = Product::query()->where('key', $key)->first();
        if (! $product instanceof Product) {
            $product = $this->products->create([
                'key' => $key,
                'name' => 'Imported ('.$state->source->label().')',
                'description' => 'Catalog imported from '.$state->source->label().'.',
            ]);
        }

        return $state->productApp['__default__'] = (string) $product->id;
    }

    // --- Prices -------------------------------------------------------------------------------

    private function price(ImportState $state, NormalizedPrice $price): void
    {
        $base = new PlannedAction(ImportEntityType::Price, $price->sourceId, $price->planSourceId, ImportOutcome::Created);

        if (! isset($state->planApp[$price->planSourceId])) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Skipped, 'Plan was not imported — price skipped.'));

            return;
        }

        if ($price->currency === null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, 'Price has no currency.'));

            return;
        }

        $ref = $state->refFor(ImportEntityType::Price, $price->sourceId);
        if ($ref !== null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Skipped, 'Already imported.')->resolvedTo('plan_price', $ref->app_id));

            return;
        }

        if (! $state->commit) {
            $this->emit($state, $base);

            return;
        }

        $plan = $state->planModel[$price->planSourceId] ?? null;
        if (! $plan instanceof Plan) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, 'Plan model unavailable at commit.'));

            return;
        }

        try {
            $saved = $this->prices->save(new PlanPriceDraft(
                planId: $plan->id,
                currency: $price->currency,
                model: PricingModel::Flat,
                priceMinor: $price->amountMinor,
                packageSize: null,
                tiers: [],
            ));
        } catch (Throwable $e) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, $e->getMessage()));

            return;
        }

        $this->refs->record($state->source, ImportEntityType::Price, $price->sourceId, 'plan_price', (string) $saved->id, $state->runId);
        $this->emit($state, $base->resolvedTo('plan_price', (string) $saved->id));
    }

    // --- Coupons ------------------------------------------------------------------------------

    private function coupon(ImportState $state, NormalizedCoupon $c): void
    {
        $code = strtoupper(trim($c->code));
        $base = new PlannedAction(ImportEntityType::Coupon, $c->sourceId, $code, ImportOutcome::Created);

        $ref = $state->refFor(ImportEntityType::Coupon, $c->sourceId);
        if ($ref !== null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Skipped, 'Already imported.')->resolvedTo('coupon', $ref->app_id));

            return;
        }

        $existing = Coupon::query()->where('code', $code)->first();
        if ($existing instanceof Coupon) {
            $state->couponModel[$code] = $existing;
            if ($state->commit) {
                $this->refs->record($state->source, ImportEntityType::Coupon, $c->sourceId, 'coupon', (string) $existing->id, $state->runId);
            }
            $this->emit($state, $base->withOutcome(ImportOutcome::Updated, 'Linked to existing coupon by code.')->resolvedTo('coupon', (string) $existing->id));

            return;
        }

        if (! $state->commit) {
            $this->emit($state, $base);

            return;
        }

        try {
            $coupon = $this->coupons->create($this->couponDraft($c, $code));
        } catch (Throwable $e) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, $e->getMessage()));

            return;
        }

        $this->backdate($coupon, $c->createdAt);
        $state->couponModel[$code] = $coupon;
        $this->refs->record($state->source, ImportEntityType::Coupon, $c->sourceId, 'coupon', (string) $coupon->id, $state->runId);
        $this->emit($state, $base->resolvedTo('coupon', (string) $coupon->id));
    }

    private function couponDraft(NormalizedCoupon $c, string $code): CouponDraft
    {
        $kind = $c->kind === 'percent' ? CouponDiscountKind::Percent : CouponDiscountKind::FixedAmount;
        $duration = CouponDuration::tryFrom($c->duration) ?? CouponDuration::Once;

        return new CouponDraft(
            code: $code,
            name: $c->name,
            kind: $kind,
            percentOff: $kind === CouponDiscountKind::Percent ? $c->percentOff : null,
            amountOffMinor: $kind === CouponDiscountKind::FixedAmount ? $c->amountOffMinor : null,
            currency: $kind === CouponDiscountKind::FixedAmount ? $c->currency : null,
            duration: $duration,
            durationInPeriods: $duration === CouponDuration::Repeating ? $c->durationInPeriods : null,
            maxRedemptions: $c->maxRedemptions,
            maxRedemptionsPerCustomer: null,
            redeemBy: $c->redeemBy !== null ? Carbon::instance($c->redeemBy) : null,
            scope: CouponScope::All,
            planKeys: [],
            active: true,
        );
    }

    // --- Customers ----------------------------------------------------------------------------

    private function customer(ImportState $state, NormalizedCustomer $c): void
    {
        $base = new PlannedAction(ImportEntityType::Customer, $c->sourceId, $c->name, ImportOutcome::Created);

        $ref = $state->refFor(ImportEntityType::Customer, $c->sourceId);
        if ($ref !== null) {
            $state->orgApp[$c->sourceId] = $ref->app_id;
            $org = Organization::query()->whereKey($ref->app_id)->first();
            if ($org instanceof Organization) {
                $state->orgModel[$c->sourceId] = $org;
            }
            $this->emit($state, $base->withOutcome(ImportOutcome::Skipped, 'Already imported.')->resolvedTo('organization', $ref->app_id));

            return;
        }

        // Duplicate-email conflict: an existing org already owns this email — an operator must
        // decide whether to link or create, so it is flagged, never silently merged or forked.
        if ($c->email !== null) {
            $existing = Organization::query()->where('billing_email', $c->email)->first();
            if ($existing instanceof Organization) {
                $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, "An organization ({$existing->id}) already uses email {$c->email}."));

                return;
            }
        }

        if (! $state->commit) {
            $state->orgApp[$c->sourceId] = '(new)';
            $this->emit($state, $base);

            return;
        }

        $orgId = 'imp_'.$state->source->value.'_'.$this->sanitize($c->sourceId);

        try {
            $org = Organization::query()->create([
                'id' => $orgId,
                'name' => $c->name,
                'billing_email' => $c->email,
                'billing_currency' => $c->currency,
                'billing_country' => $c->country,
                'tax_id' => $c->taxId,
            ]);
        } catch (Throwable $e) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, $e->getMessage()));

            return;
        }

        $this->backdate($org, $c->createdAt);
        $state->orgApp[$c->sourceId] = $orgId;
        $state->orgModel[$c->sourceId] = $org;
        $this->refs->record($state->source, ImportEntityType::Customer, $c->sourceId, 'organization', $orgId, $state->runId);
        $this->emit($state, $base->resolvedTo('organization', $orgId));
    }

    // --- Subscriptions ------------------------------------------------------------------------

    private function subscription(ImportState $state, PlanMapping $mapping, NormalizedSubscription $s): void
    {
        $base = new PlannedAction(ImportEntityType::Subscription, $s->sourceId, $s->sourceId, ImportOutcome::Created);

        $orgApp = $state->orgApp[$s->customerSourceId] ?? null;
        if ($orgApp === null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, "Customer {$s->customerSourceId} was not imported."));

            return;
        }

        // Resolve the plan: an operator mapping wins, then the plan imported in this run, then an
        // existing ledger ref. A plan resolvable by none of these is flagged, never invented.
        $planApp = $mapping->for($s->planSourceId)
            ?? $state->planApp[$s->planSourceId]
            ?? $state->refFor(ImportEntityType::Plan, $s->planSourceId)?->app_id;
        if ($planApp === null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, "Plan {$s->planSourceId} is unmapped — route it to an app plan first."));

            return;
        }

        $ref = $state->refFor(ImportEntityType::Subscription, $s->sourceId);
        if ($ref !== null) {
            $state->subApp[$s->sourceId] = $ref->app_id;
            $this->emit($state, $base->withOutcome(ImportOutcome::Skipped, 'Already imported.')->resolvedTo('subscription', $ref->app_id));

            return;
        }

        if (! $state->commit) {
            $this->emit($state, $base);

            return;
        }

        $org = $state->orgModel[$s->customerSourceId] ?? Organization::query()->whereKey($orgApp)->first();
        $plan = $state->planModel[$s->planSourceId] ?? Plan::query()->whereKey($planApp)->first();

        if (! $org instanceof Organization || ! $plan instanceof Plan) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, 'Organization or plan model unavailable at commit.'));

            return;
        }

        try {
            $currency = $this->subscribeCurrency($state, $s, $plan);
            $subscription = $this->subscriptions->subscribe($org, $plan, max(1, $s->seats), $currency);
            $this->backdateSubscription($subscription, $s, $currency);
            $this->bindCoupon($subscription, $plan, $currency, $org->id, $s->couponCode);
        } catch (Throwable $e) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, $e->getMessage()));

            return;
        }

        $state->subApp[$s->sourceId] = (string) $subscription->id;
        $this->refs->record($state->source, ImportEntityType::Subscription, $s->sourceId, 'subscription', (string) $subscription->id, $state->runId);
        $this->emit($state, $base->resolvedTo('subscription', (string) $subscription->id));
    }

    /** The currency to subscribe in: the source currency when the plan prices it, else the plan's own. */
    private function subscribeCurrency(ImportState $state, NormalizedSubscription $s, Plan $plan): string
    {
        $plan->loadMissing('prices');
        $priced = [];
        foreach ($plan->prices as $planPrice) {
            $priced[] = $planPrice->currency;
        }

        if ($s->currency !== null && in_array($s->currency, $priced, true)) {
            return $s->currency;
        }

        $hint = $state->planCurrency[$s->planSourceId] ?? null;
        if ($hint !== null && in_array($hint, $priced, true)) {
            return $hint;
        }

        return $priced[0] ?? ($s->currency ?? 'USD');
    }

    /** Preserve the subscription's historical status, period anchors, and MRR-movement timing. */
    private function backdateSubscription(Subscription $subscription, NormalizedSubscription $s, string $currency): void
    {
        $status = SubscriptionStatus::tryFrom($s->status) ?? SubscriptionStatus::Active;

        $subscription->forceFill([
            'status' => $status,
            'current_period_start' => $s->currentPeriodStart !== null ? Carbon::instance($s->currentPeriodStart) : $subscription->current_period_start,
            'current_period_end' => $s->currentPeriodEnd !== null ? Carbon::instance($s->currentPeriodEnd) : $subscription->current_period_end,
            'trial_ends_at' => $s->trialEndsAt !== null ? Carbon::instance($s->trialEndsAt) : null,
            'canceled_at' => $s->canceledAt !== null ? Carbon::instance($s->canceledAt) : null,
            'cancel_at_period_end' => false,
        ])->save();

        if ($s->createdAt !== null) {
            $this->backdate($subscription, $s->createdAt);
        }

        $this->reconcileMrr($subscription, $s, $currency, $status);
    }

    /**
     * Re-time (and, where the status does not contribute, neutralise) the MRR movement `subscribe`
     * auto-recorded, so imported history reads correctly: a serving/paying sub keeps its new-logo
     * movement dated at its real signup; a trial/paused sub contributes nothing yet; a canceled
     * sub shows the new-logo movement plus a churn dated at its cancellation.
     */
    private function reconcileMrr(Subscription $subscription, NormalizedSubscription $s, string $currency, SubscriptionStatus $status): void
    {
        $movement = SubscriptionMrrMovement::query()
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('id')
            ->first();

        if (! $movement instanceof SubscriptionMrrMovement) {
            return;
        }

        $contributes = in_array($status->value, ['active', 'past_due'], true);
        $isCanceled = $status === SubscriptionStatus::Canceled;

        if (! $contributes && ! $isCanceled) {
            // Trialing / paused: no contribution yet.
            $movement->delete();

            return;
        }

        if ($s->createdAt !== null) {
            $movement->forceFill([
                'occurred_at' => Carbon::instance($s->createdAt),
                'created_at' => Carbon::instance($s->createdAt),
            ])->save();
        }

        if ($isCanceled) {
            SubscriptionMrrMovement::query()->create([
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'currency' => $currency,
                'occurred_at' => $s->canceledAt !== null ? Carbon::instance($s->canceledAt) : Carbon::now(),
                'previous_mrr_minor' => $movement->new_mrr_minor,
                'new_mrr_minor' => 0,
                'kind' => SubscriptionMrrMovement::KIND_CHURN,
            ]);
        }
    }

    private function bindCoupon(Subscription $subscription, Plan $plan, string $currency, string $organizationId, ?string $couponCode): void
    {
        if ($couponCode === null || trim($couponCode) === '') {
            return;
        }

        try {
            $coupon = $this->redeemer->validate($couponCode, $plan, $currency, $organizationId);
            $this->redeemer->redeem($coupon, $subscription);
        } catch (Throwable) {
            // A coupon that no longer validates for this plan/currency is skipped, not fatal — the
            // subscription itself imported fine.
        }
    }

    // --- Invoices -----------------------------------------------------------------------------

    private function invoice(ImportState $state, NormalizedInvoice $inv): void
    {
        $base = new PlannedAction(ImportEntityType::Invoice, $inv->sourceId, $inv->number, ImportOutcome::Created);

        $orgApp = $state->orgApp[$inv->customerSourceId] ?? null;
        if ($orgApp === null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, "Customer {$inv->customerSourceId} was not imported."));

            return;
        }

        if ($inv->currency === null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Conflict, 'Invoice has no currency.'));

            return;
        }

        $ref = $state->refFor(ImportEntityType::Invoice, $inv->sourceId);
        if ($ref !== null) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Skipped, 'Already imported.')->resolvedTo('invoice', $ref->app_id));

            return;
        }

        if (! $state->commit) {
            $this->emit($state, $base);

            return;
        }

        // The subscription imported in this same walk (state map), else a pre-existing ledger ref.
        $subId = $inv->subscriptionSourceId !== null
            ? ($state->subApp[$inv->subscriptionSourceId]
                ?? $state->refFor(ImportEntityType::Subscription, $inv->subscriptionSourceId)?->app_id)
            : null;

        // Normalize the source status onto our vocabulary; an unmappable one lands as Open
        // (outstanding) rather than being stored raw — the same defensive tryFrom-or-default
        // the subscription import uses, so the cast can never read back an invalid case.
        $status = InvoiceStatus::tryFrom($inv->status) ?? InvoiceStatus::Open;

        try {
            $invoice = Invoice::query()->create([
                'organization_id' => $orgApp,
                'subscription_id' => $subId !== null ? (int) $subId : null,
                'period_start' => $inv->periodStart !== null ? Carbon::instance($inv->periodStart) : null,
                'period_end' => $inv->periodEnd !== null ? Carbon::instance($inv->periodEnd) : null,
                'seller' => 'imported:'.$state->source->value,
                'number' => $inv->number,
                'currency' => $inv->currency,
                'subtotal_minor' => $inv->subtotalMinor,
                'tax_minor' => $inv->taxMinor,
                'total_minor' => $inv->totalMinor,
                'status' => $status,
                'issued_at' => $inv->issuedAt !== null ? Carbon::instance($inv->issuedAt) : null,
                'paid_at' => $status->isPaid() && $inv->issuedAt !== null ? Carbon::instance($inv->issuedAt) : null,
            ]);

            foreach ($inv->lines as $line) {
                InvoiceLine::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_minor' => $line->unitAmountMinor,
                    'net_minor' => $line->amountMinor,
                    'amount_minor' => $line->amountMinor,
                ]);
            }
        } catch (Throwable $e) {
            $this->emit($state, $base->withOutcome(ImportOutcome::Failed, $e->getMessage()));

            return;
        }

        $this->backdate($invoice, $inv->issuedAt);
        $this->refs->record($state->source, ImportEntityType::Invoice, $inv->sourceId, 'invoice', (string) $invoice->id, $state->runId);
        $this->emit($state, $base->resolvedTo('invoice', (string) $invoice->id));
    }

    // --- Shared -------------------------------------------------------------------------------

    /** Record the action on the state and, when committing, write a durable log entry for it. */
    private function emit(ImportState $state, PlannedAction $action): void
    {
        $state->push($action);

        if (! $state->commit || $state->runId === null) {
            return;
        }

        ImportRunEntry::query()->create([
            'import_run_id' => $state->runId,
            'source_type' => $action->entity->value,
            'source_id' => $action->sourceId,
            'outcome' => $action->outcome->value,
            'app_type' => $action->appType,
            'app_id' => $action->appId,
            'message' => $action->message !== null ? mb_substr($action->message, 0, 1024) : null,
            'livemode' => $this->context->livemode(),
        ]);
    }

    /** Preserve a record's original creation timestamp (updated_at stays the import time). */
    private function backdate(Model $model, ?CarbonImmutable $at): void
    {
        if ($at === null) {
            return;
        }

        $model->forceFill(['created_at' => Carbon::instance($at)])->saveQuietly();
    }

    private function sanitize(string $id): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $id) ?? $id;

        return trim(mb_substr($slug, 0, 180), '_');
    }
}
