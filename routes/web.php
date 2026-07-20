<?php

declare(strict_types=1);

use App\Http\Controllers\AccessGrantController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CustomerOpsController;
use App\Http\Controllers\DsarController;
use App\Http\Controllers\DunningController;
use App\Http\Controllers\DunningStrategyController;
use App\Http\Controllers\ExemptionCertificateController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\FxRateController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InvoiceOpsController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\MailTemplateController;
use App\Http\Controllers\MeterController;
use App\Http\Controllers\OrganizationFeatureOverrideController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanCreditGrantController;
use App\Http\Controllers\PlanEntitlementController;
use App\Http\Controllers\PlanFeatureController;
use App\Http\Controllers\PricingTableController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuoteApprovalController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RetentionController;
use App\Http\Controllers\SeatController;
use App\Http\Controllers\SellerEntityController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubscriptionOpsController;
use App\Http\Controllers\TestModeController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WarehouseSinkController;
use App\Http\Controllers\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

// --- Authentication (Cbox ID as OIDC provider) ---
Route::get('/login', [AuthController::class, 'login'])->name('login');
Route::get('/auth/redirect', [AuthController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/callback', [AuthController::class, 'callback'])->name('auth.callback');
Route::post('/auth/demo', [AuthController::class, 'demo'])->name('auth.demo');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// --- The provider console (requires an authenticated Cbox ID session) ---
//
// Federated-RBAC map: each management/console surface declares the `feature:action` slug
// it needs via `billing.permission:<slug>` (the exact slugs published in the RBAC manifest,
// config/cbox-id-client.php → authz). The gate is INERT until Cbox ID emits a `permissions`
// claim AND CBOX_ID_RBAC_ENFORCE is flipped on (see App\Http\Middleware\EnforcePermission),
// so declaring it here is safe today and correct the day the signal ships. Read pages carry
// the `:read` slug; write actions carry `:manage`/`:issue`/`:revoke`.
//
// COARSE operator-org gate (SEC-1): `billing.operator` runs right after `auth.cbox` on EVERY
// console route below — a valid Cbox ID session is not enough, the principal must belong to an
// allowlisted operator organization (config/billing.php → console.operator_orgs). It is
// fail-closed (deny-all when unconfigured) and independent of the flag-gated RBAC above.
Route::middleware(['auth.cbox', 'billing.operator', 'billing.mode', 'billing.audit'])->group(function (): void {
    Route::get('/', [BillingController::class, 'dashboard'])->name('billing.dashboard');

    // --- Sandbox / test mode. The persistent toggle flips the whole console between the live
    // and test planes; the test-clock pages create/advance a virtual clock and bind test
    // subscriptions to it. Reads carry `settings:read`, writes `settings:manage`.
    Route::post('/test-mode/toggle', [TestModeController::class, 'toggle'])->name('billing.test-mode.toggle');
    Route::get('/test-mode/clocks', [TestModeController::class, 'index'])->middleware('billing.permission:settings:read')->name('billing.test-mode.clocks');
    Route::post('/test-mode/clocks', [TestModeController::class, 'store'])->middleware('billing.permission:settings:manage')->name('billing.test-mode.clocks.store');
    Route::get('/test-mode/clocks/{testClock}', [TestModeController::class, 'show'])->middleware('billing.permission:settings:read')->name('billing.test-mode.clocks.show');
    Route::post('/test-mode/clocks/{testClock}/advance', [TestModeController::class, 'advance'])->middleware('billing.permission:settings:manage')->name('billing.test-mode.clocks.advance');
    Route::post('/test-mode/clocks/{testClock}/bind', [TestModeController::class, 'bind'])->middleware('billing.permission:settings:manage')->name('billing.test-mode.clocks.bind');
    Route::post('/test-mode/clocks/{testClock}/unbind/{subscription}', [TestModeController::class, 'unbind'])->middleware('billing.permission:settings:manage')->name('billing.test-mode.clocks.unbind');
    Route::post('/test-mode/clocks/{testClock}/outcome', [TestModeController::class, 'outcome'])->middleware('billing.permission:settings:manage')->name('billing.test-mode.clocks.outcome');

    Route::get('/subscriptions', [BillingController::class, 'subscriptions'])->middleware('billing.permission:subscriptions:read')->name('billing.subscriptions');
    Route::get('/subscriptions/dunning', [BillingController::class, 'dunning'])->middleware('billing.permission:subscriptions:read')->name('billing.subscriptions.dunning');

    // Manual dunning controls (Wave 3): retry-now + stop-dunning (with the terminal choice).
    Route::post('/subscriptions/dunning/{retry}/retry', [DunningController::class, 'retry'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.dunning.retry');
    Route::post('/subscriptions/dunning/{retry}/stop', [DunningController::class, 'stop'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.dunning.stop');

    // --- Subscription operator lifecycle (Wave 3): create + plan change / quantity /
    // add-ons (each preview → confirm through the engine) + cancel a scheduled change.
    // Reads carry `subscriptions:read`; every write carries `subscriptions:manage`.
    // `/subscriptions/new` is declared before `/subscriptions/{subscription}`.
    Route::get('/subscriptions/new', [SubscriptionOpsController::class, 'create'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.create');
    Route::post('/subscriptions', [SubscriptionOpsController::class, 'store'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.store');

    Route::get('/subscriptions/{subscription}', [BillingController::class, 'subscription'])->middleware('billing.permission:subscriptions:read')->name('billing.subscriptions.show');

    Route::post('/subscriptions/{subscription}/plan-change/preview', [SubscriptionOpsController::class, 'planChangePreview'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.plan-change.preview');
    Route::post('/subscriptions/{subscription}/plan-change', [SubscriptionOpsController::class, 'planChange'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.plan-change');
    Route::post('/subscriptions/{subscription}/quantity/preview', [SubscriptionOpsController::class, 'quantityPreview'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.quantity.preview');
    Route::post('/subscriptions/{subscription}/quantity', [SubscriptionOpsController::class, 'quantity'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.quantity');
    Route::post('/subscriptions/{subscription}/addons/preview', [SubscriptionOpsController::class, 'addOnPreview'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.addons.preview');
    Route::post('/subscriptions/{subscription}/addons', [SubscriptionOpsController::class, 'addAddOn'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.addons.add');
    Route::post('/subscriptions/{subscription}/addons/remove', [SubscriptionOpsController::class, 'removeAddOn'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.addons.remove');
    Route::post('/subscriptions/{subscription}/scheduled-change/cancel', [SubscriptionOpsController::class, 'cancelScheduledChange'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.scheduled-change.cancel');

    // Retention actions (App-A ManagesRetention): cancel-with-reason / pause / reactivate.
    Route::post('/subscriptions/{subscription}/cancel', [RetentionController::class, 'cancel'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.cancel');
    Route::post('/subscriptions/{subscription}/reactivate', [RetentionController::class, 'reactivate'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.reactivate');

    // Seats (purchased + explicitly-assigned): buy/release purchased Full seats (billed
    // quantity, guardrailed against the assigned count) and assign/unassign a purchased seat
    // to a specific eligible member. Mutating, so `subscriptions:manage`.
    Route::post('/subscriptions/{subscription}/seats', [SeatController::class, 'setPurchased'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.seats.set');
    Route::post('/subscriptions/{subscription}/seats/assign', [SeatController::class, 'assign'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.seats.assign');
    Route::post('/subscriptions/{subscription}/seats/unassign', [SeatController::class, 'unassign'])->middleware('billing.permission:subscriptions:manage')->name('billing.subscriptions.seats.unassign');

    // --- CPQ: sales quotes + contracts (Wave 5). A rep authors a quote, it is threshold-routed
    // for approval, sent to the customer as a branded order form, accepted by e-signature, and
    // provisions a subscription. Reads carry `quotes:read`, authoring/lifecycle `quotes:manage`,
    // and the deal-desk decision `quotes:approve` (a rep cannot self-approve). `/approvals` and
    // `/new` are declared before `/{quote}` so the static segments are not captured by binding.
    Route::get('/quotes', [QuoteController::class, 'index'])->middleware('billing.permission:quotes:read')->name('billing.quotes');
    Route::get('/quotes/approvals', [QuoteApprovalController::class, 'index'])->middleware('billing.permission:quotes:read')->name('billing.quotes.approvals');
    Route::get('/quotes/new', [QuoteController::class, 'create'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.create');
    Route::post('/quotes', [QuoteController::class, 'store'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.store');
    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->middleware('billing.permission:quotes:read')->name('billing.quotes.show');
    Route::get('/quotes/{quote}/edit', [QuoteController::class, 'edit'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.edit');
    Route::put('/quotes/{quote}', [QuoteController::class, 'update'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.update');
    Route::post('/quotes/{quote}/submit', [QuoteController::class, 'submit'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.submit');
    Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.send');
    Route::post('/quotes/{quote}/resend', [QuoteController::class, 'resend'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.resend');
    Route::post('/quotes/{quote}/expire', [QuoteController::class, 'expire'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.expire');
    Route::post('/quotes/{quote}/clone', [QuoteController::class, 'clone'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.clone');
    Route::post('/quotes/{quote}/approve', [QuoteApprovalController::class, 'approve'])->middleware('billing.permission:quotes:approve')->name('billing.quotes.approve');
    Route::post('/quotes/{quote}/reject', [QuoteApprovalController::class, 'reject'])->middleware('billing.permission:quotes:approve')->name('billing.quotes.reject');
    Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy'])->middleware('billing.permission:quotes:manage')->name('billing.quotes.destroy');

    // --- Revenue analytics (engine Reporting module) ---
    Route::get('/analytics/revenue', [AnalyticsController::class, 'revenue'])->middleware('billing.permission:analytics:read')->name('analytics.revenue');
    Route::get('/analytics/retention', [AnalyticsController::class, 'retention'])->middleware('billing.permission:analytics:read')->name('analytics.retention');

    // --- Data export + warehouse sinks (Data area). Streamed dataset downloads (CSV/NDJSON)
    // carry `analytics:read`; the warehouse-sink control plane (configure/run/manifests) carries
    // `settings:read`/`settings:manage`. The stream never buffers a whole dataset; the sink stages
    // partitioned files + load manifests (the real warehouse ingestion pattern).
    Route::get('/exports', [ExportController::class, 'index'])->middleware('billing.permission:analytics:read')->name('billing.exports');
    Route::get('/exports/download', [ExportController::class, 'download'])->middleware('billing.permission:analytics:read')->name('billing.exports.download');

    Route::get('/exports/warehouse', [WarehouseSinkController::class, 'index'])->middleware('billing.permission:settings:read')->name('billing.exports.warehouse');
    Route::post('/exports/warehouse', [WarehouseSinkController::class, 'store'])->middleware('billing.permission:settings:manage')->name('billing.exports.warehouse.store');
    Route::post('/exports/warehouse/{warehouseSink}', [WarehouseSinkController::class, 'update'])->middleware('billing.permission:settings:manage')->name('billing.exports.warehouse.update');
    Route::post('/exports/warehouse/{warehouseSink}/toggle', [WarehouseSinkController::class, 'toggle'])->middleware('billing.permission:settings:manage')->name('billing.exports.warehouse.toggle');
    Route::post('/exports/warehouse/{warehouseSink}/run', [WarehouseSinkController::class, 'run'])->middleware('billing.permission:settings:manage')->name('billing.exports.warehouse.run');
    Route::delete('/exports/warehouse/{warehouseSink}', [WarehouseSinkController::class, 'destroy'])->middleware('billing.permission:settings:manage')->name('billing.exports.warehouse.destroy');
    Route::get('/exports/warehouse/{warehouseSink}/manifest/{dataset}', [WarehouseSinkController::class, 'manifest'])->middleware('billing.permission:settings:read')->name('billing.exports.warehouse.manifest');

    // --- Import / migration (Data area). Bring a seller's catalog, customers, subscriptions and
    // historical invoices over from Stripe / Chargebee / Recurly by uploading their export file(s):
    // a dry-run report (counts + conflicts + the proposed plan mapping) precedes an idempotent,
    // audit-logged commit (queued for large sets). The preview writes nothing; the commit + run
    // views carry `settings:manage`. The preview is a POST but writes no domain data — only a
    // planned run row — so it is named `.preview` to stay out of the audit trail.
    Route::get('/import', [ImportController::class, 'index'])->middleware('billing.permission:settings:manage')->name('billing.import');
    Route::post('/import/preview', [ImportController::class, 'preview'])->middleware('billing.permission:settings:manage')->name('billing.import.preview');
    Route::post('/import/{importRun}/commit', [ImportController::class, 'commit'])->middleware('billing.permission:settings:manage')->name('billing.import.commit');
    Route::get('/import/runs/{importRun}', [ImportController::class, 'show'])->middleware('billing.permission:settings:manage')->name('billing.import.runs.show');

    // --- Tamper-evident operator audit log + GDPR/DSAR tooling. The trail is read (searchable,
    // paginated, filterable) and exported under `settings:read`; the DSAR access export reads a
    // subject's data under `customers:read`; the right-to-be-forgotten erasure — which
    // pseudonymizes PII while retaining de-identified financial records — is the most sensitive
    // write and carries `customers:manage`. Every write here is itself audit-logged.
    Route::get('/audit', [AuditLogController::class, 'index'])->middleware('billing.permission:settings:read')->name('billing.audit');
    Route::get('/audit/export', [AuditLogController::class, 'export'])->middleware('billing.permission:settings:read')->name('billing.audit.export');
    Route::get('/audit/gdpr', [DsarController::class, 'index'])->middleware('billing.permission:customers:read')->name('billing.audit.gdpr');
    Route::get('/audit/gdpr/{organization}/export', [DsarController::class, 'export'])->middleware('billing.permission:customers:read')->name('billing.audit.gdpr.export');
    Route::post('/audit/gdpr/{organization}/erase', [DsarController::class, 'erase'])->middleware('billing.permission:customers:manage')->name('billing.audit.gdpr.erase');
    Route::get('/audit/{event}', [AuditLogController::class, 'show'])->middleware('billing.permission:settings:read')->name('billing.audit.show');

    Route::get('/invoices', [BillingController::class, 'invoices'])->middleware('billing.permission:invoices:read')->name('billing.invoices');

    // --- Invoice lifecycle actions (Wave 3): manual create + void/refund/mark-paid/resend.
    // Reads carry `invoices:read`; lifecycle writes (create/void/mark-paid/resend) carry
    // `invoices:manage`, while a money-returning refund carries the narrower `invoices:refund`
    // (Wave 4 slug split). Every action is guarded server-side in InvoiceOperations. `/invoices/new`
    // is declared before `/invoices/{invoice}` so the static segment is never captured by the binding.
    Route::get('/invoices/new', [InvoiceOpsController::class, 'create'])->middleware('billing.permission:invoices:manage')->name('billing.invoices.create');
    Route::post('/invoices', [InvoiceOpsController::class, 'store'])->middleware('billing.permission:invoices:manage')->name('billing.invoices.store');

    // Credit notes (Wave 3): the legal record surface for refunds/adjustments, read-only
    // (issued only by the engine). `/credit-notes/{creditNote}` binds by id.
    Route::get('/credit-notes', [CreditNoteController::class, 'index'])->middleware('billing.permission:invoices:read')->name('billing.credit-notes');
    Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show'])->middleware('billing.permission:invoices:read')->name('billing.credit-notes.show');

    Route::get('/invoices/{invoice}', [BillingController::class, 'invoice'])->middleware('billing.permission:invoices:read')->name('billing.invoices.show');
    Route::get('/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->middleware('billing.permission:invoices:read')->name('billing.invoices.pdf');

    Route::post('/invoices/{invoice}/void', [InvoiceOpsController::class, 'void'])->middleware('billing.permission:invoices:manage')->name('billing.invoices.void');
    Route::post('/invoices/{invoice}/refund', [InvoiceOpsController::class, 'refund'])->middleware('billing.permission:invoices:refund')->name('billing.invoices.refund');
    Route::post('/invoices/{invoice}/mark-paid', [InvoiceOpsController::class, 'markPaid'])->middleware('billing.permission:invoices:manage')->name('billing.invoices.mark-paid');
    Route::post('/invoices/{invoice}/resend', [InvoiceOpsController::class, 'resend'])->middleware('billing.permission:invoices:manage')->name('billing.invoices.resend');

    Route::get('/usage', [BillingController::class, 'usage'])->middleware('billing.permission:usage:read')->name('billing.usage');
    Route::get('/catalog', [BillingController::class, 'catalog'])->middleware('billing.permission:catalog:read')->name('billing.catalog');
    Route::get('/pricing', [BillingController::class, 'pricing'])->middleware('billing.permission:catalog:read')->name('billing.pricing');

    // --- Catalog CRUD (Wave 2): routable pages + full authoring for the whole catalog.
    // Reads carry `catalog:read`, writes `catalog:manage`. Destructive actions are guarded
    // server-side (referential integrity / grandfathering) in the authoring services, never
    // on the confirm dialog alone. `…/new` is declared before `…/{model}` so the static
    // segment is never captured by the model binding.

    // Products — routable list + detail + create/edit/archive/delete (archive when it has plans).
    Route::get('/products', [ProductController::class, 'index'])->middleware('billing.permission:catalog:read')->name('billing.products');
    Route::get('/products/new', [ProductController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.products.create');
    Route::post('/products', [ProductController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.products.store');
    Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('billing.permission:catalog:read')->name('billing.products.show');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.products.update');
    Route::post('/products/{product}/archive', [ProductController::class, 'archive'])->middleware('billing.permission:catalog:manage')->name('billing.products.archive');
    Route::post('/products/{product}/unarchive', [ProductController::class, 'unarchive'])->middleware('billing.permission:catalog:manage')->name('billing.products.unarchive');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.products.destroy');

    // Pricing tables (#57) — author the embeddable, public pricing table + paywall surface:
    // which plans/features to compare, currencies, the interval toggle, branding and the CTA
    // deep-link. The detail page renders the ACTUAL table (live preview) + the copy-paste embed
    // snippet. A pricing table is a pure catalog projection (grants nothing, no subscriber
    // depends on it), so it is safe to hard-delete; the `active` flag takes one offline instead.
    Route::get('/pricing-tables', [PricingTableController::class, 'index'])->middleware('billing.permission:catalog:read')->name('billing.pricing-tables');
    Route::get('/pricing-tables/new', [PricingTableController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.pricing-tables.create');
    Route::post('/pricing-tables', [PricingTableController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.pricing-tables.store');
    Route::get('/pricing-tables/{pricing_table}', [PricingTableController::class, 'show'])->middleware('billing.permission:catalog:read')->name('billing.pricing-tables.show');
    Route::get('/pricing-tables/{pricing_table}/edit', [PricingTableController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.pricing-tables.edit');
    Route::get('/pricing-tables/{pricing_table}/preview', [PricingTableController::class, 'preview'])->middleware('billing.permission:catalog:read')->name('billing.pricing-tables.preview');
    Route::put('/pricing-tables/{pricing_table}', [PricingTableController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.pricing-tables.update');
    Route::post('/pricing-tables/{pricing_table}/activate', [PricingTableController::class, 'activate'])->middleware('billing.permission:catalog:manage')->name('billing.pricing-tables.activate');
    Route::post('/pricing-tables/{pricing_table}/deactivate', [PricingTableController::class, 'deactivate'])->middleware('billing.permission:catalog:manage')->name('billing.pricing-tables.deactivate');
    Route::delete('/pricing-tables/{pricing_table}', [PricingTableController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.pricing-tables.destroy');

    // Coupons — routable list + detail + create/edit/archive/delete. A redeemed coupon is
    // archived (its ledger + live discounts preserved); only a never-redeemed coupon is
    // hard-deleted. Same catalog:read / catalog:manage gate as the rest of the catalog.
    Route::get('/coupons', [CouponController::class, 'index'])->middleware('billing.permission:catalog:read')->name('billing.coupons');
    Route::get('/coupons/new', [CouponController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.coupons.create');
    Route::post('/coupons', [CouponController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.coupons.store');
    Route::get('/coupons/{coupon}', [CouponController::class, 'show'])->middleware('billing.permission:catalog:read')->name('billing.coupons.show');
    Route::get('/coupons/{coupon}/edit', [CouponController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.coupons.edit');
    Route::put('/coupons/{coupon}', [CouponController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.coupons.update');
    Route::post('/coupons/{coupon}/archive', [CouponController::class, 'archive'])->middleware('billing.permission:catalog:manage')->name('billing.coupons.archive');
    Route::post('/coupons/{coupon}/unarchive', [CouponController::class, 'unarchive'])->middleware('billing.permission:catalog:manage')->name('billing.coupons.unarchive');
    Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.coupons.destroy');

    // Catalog price authoring: create/edit a plan price and (for tiered models) its tier
    // table; delete a price version (guarded by the currency-lock invariant).
    Route::get('/catalog/prices/new', [CatalogController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.create');
    Route::post('/catalog/prices', [CatalogController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.store');
    Route::get('/catalog/prices/{price}/edit', [CatalogController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.edit');
    Route::put('/catalog/prices/{price}', [CatalogController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.update');
    Route::delete('/catalog/prices/{price}', [CatalogController::class, 'destroyPrice'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.destroy');

    // Plans — the per-plan detail hub + metadata create/edit/archive/delete, plus its
    // entitlement and credit-grant editors (scope-bound to the plan). `…/new` before `{plan}`.
    Route::get('/catalog/plans/new', [PlanController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.plans.create');
    Route::post('/catalog/plans', [PlanController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.plans.store');
    Route::get('/catalog/plans/{plan}', [PlanController::class, 'show'])->middleware('billing.permission:catalog:read')->name('billing.plans.show');
    Route::get('/catalog/plans/{plan}/edit', [PlanController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.plans.edit');
    Route::put('/catalog/plans/{plan}', [PlanController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.plans.update');
    Route::post('/catalog/plans/{plan}/archive', [PlanController::class, 'archive'])->middleware('billing.permission:catalog:manage')->name('billing.plans.archive');
    Route::post('/catalog/plans/{plan}/unarchive', [PlanController::class, 'unarchive'])->middleware('billing.permission:catalog:manage')->name('billing.plans.unarchive');
    Route::delete('/catalog/plans/{plan}', [PlanController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.plans.destroy');

    Route::scopeBindings()->group(function (): void {
        // Plan entitlements — full editor (create/edit/delete) reachable from the plan detail.
        Route::get('/catalog/plans/{plan}/entitlements/new', [PlanEntitlementController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.plans.entitlements.create');
        Route::post('/catalog/plans/{plan}/entitlements', [PlanEntitlementController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.plans.entitlements.store');
        Route::get('/catalog/plans/{plan}/entitlements/{entitlement}/edit', [PlanEntitlementController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.plans.entitlements.edit');
        Route::put('/catalog/plans/{plan}/entitlements/{entitlement}', [PlanEntitlementController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.plans.entitlements.update');
        Route::delete('/catalog/plans/{plan}/entitlements/{entitlement}', [PlanEntitlementController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.plans.entitlements.destroy');

        // Plan credit grants — full editor (create/edit/delete) reachable from the plan detail.
        Route::get('/catalog/plans/{plan}/credit-grants/new', [PlanCreditGrantController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.plans.credit-grants.create');
        Route::post('/catalog/plans/{plan}/credit-grants', [PlanCreditGrantController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.plans.credit-grants.store');
        Route::get('/catalog/plans/{plan}/credit-grants/{credit_grant}/edit', [PlanCreditGrantController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.plans.credit-grants.edit');
        Route::put('/catalog/plans/{plan}/credit-grants/{credit_grant}', [PlanCreditGrantController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.plans.credit-grants.update');
        Route::delete('/catalog/plans/{plan}/credit-grants/{credit_grant}', [PlanCreditGrantController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.plans.credit-grants.destroy');

        // Plan feature grants (boolean/config product gating) — which features the plan grants,
        // authored on the plan detail hub alongside the metered entitlements + credit grants.
        Route::get('/catalog/plans/{plan}/features/new', [PlanFeatureController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.plans.features.create');
        Route::post('/catalog/plans/{plan}/features', [PlanFeatureController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.plans.features.store');
        Route::get('/catalog/plans/{plan}/features/{feature}/edit', [PlanFeatureController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.plans.features.edit');
        Route::put('/catalog/plans/{plan}/features/{feature}', [PlanFeatureController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.plans.features.update');
        Route::delete('/catalog/plans/{plan}/features/{feature}', [PlanFeatureController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.plans.features.destroy');
    });

    // Features — boolean/config product-gating catalog, full CRUD. The boolean/config peer of the
    // Meters catalog above; a referenced feature is archived (grants keep resolving), never deleted.
    Route::get('/features', [FeatureController::class, 'index'])->middleware('billing.permission:catalog:read')->name('billing.features');
    Route::get('/features/new', [FeatureController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.features.create');
    Route::post('/features', [FeatureController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.features.store');
    Route::get('/features/{feature}', [FeatureController::class, 'show'])->middleware('billing.permission:catalog:read')->name('billing.features.show');
    Route::get('/features/{feature}/edit', [FeatureController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.features.edit');
    Route::put('/features/{feature}', [FeatureController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.features.update');
    Route::post('/features/{feature}/archive', [FeatureController::class, 'archive'])->middleware('billing.permission:catalog:manage')->name('billing.features.archive');
    Route::post('/features/{feature}/unarchive', [FeatureController::class, 'unarchive'])->middleware('billing.permission:catalog:manage')->name('billing.features.unarchive');
    Route::delete('/features/{feature}', [FeatureController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.features.destroy');

    // Plan retirement authoring (ADR-0016): mark a plan retiring / un-retire it.
    Route::post('/catalog/plans/{plan}/retire', [CatalogController::class, 'retire'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.plans.retire');
    Route::post('/catalog/plans/{plan}/unretire', [CatalogController::class, 'unretire'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.plans.unretire');

    // Meters — full CRUD (list + detail + create/edit/archive/delete). Archived/referenced
    // meters keep resolving their historical policy; only never-referenced meters delete.
    Route::get('/meters', [MeterController::class, 'index'])->middleware('billing.permission:catalog:read')->name('billing.meters');
    Route::get('/meters/new', [MeterController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.meters.create');
    Route::post('/meters', [MeterController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.meters.store');
    Route::get('/meters/{meter}', [MeterController::class, 'show'])->middleware('billing.permission:catalog:read')->name('billing.meters.show');
    Route::get('/meters/{meter}/edit', [MeterController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.meters.edit');
    Route::put('/meters/{meter}', [MeterController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.meters.update');
    Route::post('/meters/{meter}/archive', [MeterController::class, 'archive'])->middleware('billing.permission:catalog:manage')->name('billing.meters.archive');
    Route::post('/meters/{meter}/unarchive', [MeterController::class, 'unarchive'])->middleware('billing.permission:catalog:manage')->name('billing.meters.unarchive');
    Route::delete('/meters/{meter}', [MeterController::class, 'destroy'])->middleware('billing.permission:catalog:manage')->name('billing.meters.destroy');

    Route::get('/customers', [BillingController::class, 'customers'])->middleware('billing.permission:customers:read')->name('billing.customers');

    // Access grants (Wave 4): a read-only viewer over the RBAC mirror the provisioning
    // webhooks maintain (Cbox ID owns assignment). `/access-grants` before `{organization}`.
    Route::get('/access-grants', [AccessGrantController::class, 'index'])->middleware('billing.permission:customers:read')->name('billing.access-grants');

    // Tax-exemption overview (who is exempt where) — a static segment declared BEFORE the
    // `{organization}` binding so it is never captured as an org id.
    Route::get('/tax-exemptions', [ExemptionCertificateController::class, 'overview'])->middleware('billing.permission:customers:read')->name('billing.tax-exemptions');

    Route::get('/customers/{organization}', [BillingController::class, 'customer'])->middleware('billing.permission:customers:read')->name('billing.customers.show');

    // --- Organization management (Wave 4). Suspend/reactivate and the profile edit flip the
    // app + engine standing and persist the org fields (currency guarded by the one-way lock);
    // gated `customers:manage`. Payment-method set-default/remove proxy the gateway; gated
    // `payments:manage`. The manual gateway vaults nothing, so its methods stay read-only.
    Route::post('/customers/{organization}/suspend', [CustomerOpsController::class, 'suspend'])->middleware('billing.permission:customers:manage')->name('billing.customers.suspend');
    Route::post('/customers/{organization}/reactivate', [CustomerOpsController::class, 'reactivate'])->middleware('billing.permission:customers:manage')->name('billing.customers.reactivate');
    Route::put('/customers/{organization}', [CustomerOpsController::class, 'updateProfile'])->middleware('billing.permission:customers:manage')->name('billing.customers.update');
    Route::post('/customers/{organization}/payment-methods/default', [CustomerOpsController::class, 'setDefaultPaymentMethod'])->middleware('billing.permission:payments:manage')->name('billing.customers.payment-methods.default');
    Route::post('/customers/{organization}/payment-methods/remove', [CustomerOpsController::class, 'removePaymentMethod'])->middleware('billing.permission:payments:manage')->name('billing.customers.payment-methods.remove');

    // Tax exemption certificates: upload/verify/reject are gated `customers:manage`; the
    // document download is gated `customers:read` and streams from the PRIVATE disk after an
    // ownership check (a cross-org certificate id is 404).
    Route::post('/customers/{organization}/exemptions', [ExemptionCertificateController::class, 'store'])->middleware('billing.permission:customers:manage')->name('billing.customers.exemptions.store');
    Route::get('/customers/{organization}/exemptions/{certificate}/download', [ExemptionCertificateController::class, 'download'])->middleware('billing.permission:customers:read')->name('billing.customers.exemptions.download');
    Route::post('/customers/{organization}/exemptions/{certificate}/verify', [ExemptionCertificateController::class, 'verify'])->middleware('billing.permission:customers:manage')->name('billing.customers.exemptions.verify');
    Route::post('/customers/{organization}/exemptions/{certificate}/reject', [ExemptionCertificateController::class, 'reject'])->middleware('billing.permission:customers:manage')->name('billing.customers.exemptions.reject');

    // Wallet / credits (Wave 3): an operator credit adjustment (promotional/goodwill grant
    // or correcting debit) through the engine wallet with an audit row. Gated on the dedicated
    // `wallet:manage` slug (Wave 4 split from `customers:manage`).
    Route::post('/customers/{organization}/wallet/adjust', [WalletController::class, 'adjust'])->middleware('billing.permission:wallet:manage')->name('billing.customers.wallet.adjust');

    // Org-level feature overrides: grant/revoke a boolean/config feature for one customer (wins
    // over the plan resolution), or clear the override to restore the plan-resolved value. Every
    // write is audit-logged by the service. Gated `customers:manage`.
    Route::post('/customers/{organization}/features/override', [OrganizationFeatureOverrideController::class, 'override'])->middleware('billing.permission:customers:manage')->name('billing.customers.features.override');
    Route::post('/customers/{organization}/features/clear', [OrganizationFeatureOverrideController::class, 'clear'])->middleware('billing.permission:customers:manage')->name('billing.customers.features.clear');

    // --- On-prem licensing (issuer console) ---
    // Gated on the `licenses` console-kit feature (presence gate): the routes 404 when
    // the feature is off. The base registers it always-on, so it is reachable here.
    Route::middleware('console.feature:licenses')->group(function (): void {
        Route::get('/licenses', [LicenseController::class, 'index'])->middleware('billing.permission:licenses:read')->name('billing.licenses');
        Route::get('/licenses/distribution', [LicenseController::class, 'settings'])->middleware('billing.permission:licenses:read')->name('billing.licenses.settings');
        Route::post('/licenses', [LicenseController::class, 'issue'])->middleware('billing.permission:licenses:issue')->name('billing.licenses.issue');
        Route::post('/licenses/{id}/renew', [LicenseController::class, 'renew'])->middleware('billing.permission:licenses:issue')->name('billing.licenses.renew');
        Route::post('/licenses/{id}/revoke', [LicenseController::class, 'revoke'])->middleware('billing.permission:licenses:revoke')->name('billing.licenses.revoke');
        // Per-license detail — declared AFTER `/licenses/distribution` so the static segment
        // is never captured by the `{id}` binding.
        Route::get('/licenses/{id}', [LicenseController::class, 'show'])->middleware('billing.permission:licenses:read')->name('billing.licenses.show');
    });

    Route::get('/settings', [BillingController::class, 'settings'])->middleware('billing.permission:settings:read')->name('billing.settings');

    // --- Platform settings CRUD (Wave 4). Reads carry `settings:read`, writes `settings:manage`.
    //
    // Two DB-backed resources get real authoring: selling entities (+ per-jurisdiction tax
    // registrations) and API tokens (mint shows the plaintext once — only the hash is stored —
    // and revoke is a confirmed soft-revoke). Gateways and webhook receivers are env-driven, so
    // they get honest status + guided-config pages, not a fabricated DB config. `…/new` is
    // declared before `{sellerEntity}` so the static segment is never captured by the binding.
    Route::get('/settings/sellers/new', [SellerEntityController::class, 'create'])->middleware('billing.permission:settings:manage')->name('billing.settings.sellers.create');
    Route::post('/settings/sellers', [SellerEntityController::class, 'store'])->middleware('billing.permission:settings:manage')->name('billing.settings.sellers.store');
    Route::get('/settings/sellers/{sellerEntity}/edit', [SellerEntityController::class, 'edit'])->middleware('billing.permission:settings:manage')->name('billing.settings.sellers.edit');
    Route::put('/settings/sellers/{sellerEntity}', [SellerEntityController::class, 'update'])->middleware('billing.permission:settings:manage')->name('billing.settings.sellers.update');
    Route::post('/settings/sellers/{sellerEntity}/default', [SellerEntityController::class, 'setDefault'])->middleware('billing.permission:settings:manage')->name('billing.settings.sellers.default');
    Route::post('/settings/sellers/{sellerEntity}/archive', [SellerEntityController::class, 'archive'])->middleware('billing.permission:settings:manage')->name('billing.settings.sellers.archive');
    Route::post('/settings/sellers/{sellerEntity}/unarchive', [SellerEntityController::class, 'unarchive'])->middleware('billing.permission:settings:manage')->name('billing.settings.sellers.unarchive');
    Route::delete('/settings/sellers/{sellerEntity}', [SellerEntityController::class, 'destroy'])->middleware('billing.permission:settings:manage')->name('billing.settings.sellers.destroy');

    // --- FX rates for consolidated reporting. The rates view is a read; refreshing the ECB feed
    // and authoring an override rate are writes (settings:manage).
    Route::get('/settings/fx', [FxRateController::class, 'index'])->middleware('billing.permission:settings:read')->name('billing.settings.fx');
    Route::post('/settings/fx/refresh', [FxRateController::class, 'refresh'])->middleware('billing.permission:settings:manage')->name('billing.settings.fx.refresh');
    Route::post('/settings/fx/overrides', [FxRateController::class, 'storeOverride'])->middleware('billing.permission:settings:manage')->name('billing.settings.fx.overrides');

    Route::get('/settings/api-tokens/new', [ApiTokenController::class, 'create'])->middleware('billing.permission:settings:manage')->name('billing.settings.tokens.create');
    Route::post('/settings/api-tokens', [ApiTokenController::class, 'store'])->middleware('billing.permission:settings:manage')->name('billing.settings.tokens.store');
    Route::post('/settings/api-tokens/{apiToken}/revoke', [ApiTokenController::class, 'revoke'])->middleware('billing.permission:settings:manage')->name('billing.settings.tokens.revoke');

    Route::get('/settings/gateways', [SettingsController::class, 'gateways'])->middleware('billing.permission:settings:read')->name('billing.settings.gateways');

    // Adaptive-dunning strategy config: view the effective per-decline-category recovery plans
    // and tune a category's curve/heuristics at runtime (persisted to dunning_strategies).
    // Reads carry `settings:read`; writes carry `settings:manage`.
    Route::get('/settings/dunning', [DunningStrategyController::class, 'index'])->middleware('billing.permission:settings:read')->name('billing.settings.dunning');
    Route::get('/settings/dunning/{category}/edit', [DunningStrategyController::class, 'edit'])->middleware('billing.permission:settings:manage')->name('billing.settings.dunning.edit');
    Route::put('/settings/dunning/{category}', [DunningStrategyController::class, 'update'])->middleware('billing.permission:settings:manage')->name('billing.settings.dunning.update');
    Route::post('/settings/dunning/{category}/reset', [DunningStrategyController::class, 'reset'])->middleware('billing.permission:settings:manage')->name('billing.settings.dunning.reset');

    // --- Outbound webhooks / event bus. DB-backed endpoint CRUD replaces the old env-status page.
    // Reads (`settings:read`) list endpoints + the per-endpoint delivery log; writes
    // (`settings:manage`) register/edit/rotate/activate/delete, send a test ping, and redeliver a
    // failed/dead delivery. `…/new` is declared before `{webhookEndpoint}` so the static segment is
    // never captured by the binding.
    Route::get('/settings/webhooks', [WebhookEndpointController::class, 'index'])->middleware('billing.permission:settings:read')->name('billing.settings.webhooks');
    Route::get('/settings/webhooks/new', [WebhookEndpointController::class, 'create'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.create');
    Route::post('/settings/webhooks', [WebhookEndpointController::class, 'store'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.store');
    Route::get('/settings/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'show'])->middleware('billing.permission:settings:read')->name('billing.settings.webhooks.show');
    Route::get('/settings/webhooks/{webhookEndpoint}/edit', [WebhookEndpointController::class, 'edit'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.edit');
    Route::put('/settings/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.update');
    Route::post('/settings/webhooks/{webhookEndpoint}/rotate', [WebhookEndpointController::class, 'rotate'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.rotate');
    Route::post('/settings/webhooks/{webhookEndpoint}/activate', [WebhookEndpointController::class, 'activate'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.activate');
    Route::post('/settings/webhooks/{webhookEndpoint}/deactivate', [WebhookEndpointController::class, 'deactivate'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.deactivate');
    Route::post('/settings/webhooks/{webhookEndpoint}/test', [WebhookEndpointController::class, 'test'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.test');
    Route::delete('/settings/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.destroy');
    Route::post('/settings/webhooks/{webhookEndpoint}/deliveries/{delivery}/redeliver', [WebhookEndpointController::class, 'redeliver'])->middleware('billing.permission:settings:manage')->name('billing.settings.webhooks.redeliver');

    // --- Transactional emails / notifications (Wave 5). The brandable + localized lifecycle
    // email system: list every event type × locale × seller with its resolved source, edit a
    // template with a live server-rendered preview of the ACTUAL branded email, reset an
    // override to the shipped default, and send a test email through the real notifier
    // (honouring test-mode capture). Reads carry `settings:read`; writes carry `settings:manage`.
    // The preview renders through the sandboxed pipeline and persists nothing, so it is read.
    Route::get('/settings/emails', [MailTemplateController::class, 'index'])->middleware('billing.permission:settings:read')->name('billing.settings.emails');
    Route::match(['get', 'post'], '/settings/emails/{eventType}/preview', [MailTemplateController::class, 'preview'])->middleware('billing.permission:settings:read')->name('billing.settings.emails.preview');
    Route::get('/settings/emails/{eventType}/edit', [MailTemplateController::class, 'edit'])->middleware('billing.permission:settings:manage')->name('billing.settings.emails.edit');
    Route::put('/settings/emails/{eventType}', [MailTemplateController::class, 'update'])->middleware('billing.permission:settings:manage')->name('billing.settings.emails.update');
    Route::post('/settings/emails/{eventType}/reset', [MailTemplateController::class, 'reset'])->middleware('billing.permission:settings:manage')->name('billing.settings.emails.reset');
    Route::post('/settings/emails/{eventType}/test', [MailTemplateController::class, 'testSend'])->middleware('billing.permission:settings:manage')->name('billing.settings.emails.test');
});
