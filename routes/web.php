<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\InvoiceOpsController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\MeterController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanCreditGrantController;
use App\Http\Controllers\PlanEntitlementController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RetentionController;
use App\Http\Controllers\SeatController;
use App\Http\Controllers\SubscriptionOpsController;
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
Route::middleware('auth.cbox')->group(function (): void {
    Route::get('/', [BillingController::class, 'dashboard'])->name('billing.dashboard');

    Route::get('/subscriptions', [BillingController::class, 'subscriptions'])->middleware('billing.permission:subscriptions:read')->name('billing.subscriptions');
    Route::get('/subscriptions/dunning', [BillingController::class, 'dunning'])->middleware('billing.permission:subscriptions:read')->name('billing.subscriptions.dunning');

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

    // --- Revenue analytics (engine Reporting module) ---
    Route::get('/analytics/revenue', [AnalyticsController::class, 'revenue'])->middleware('billing.permission:analytics:read')->name('analytics.revenue');
    Route::get('/analytics/retention', [AnalyticsController::class, 'retention'])->middleware('billing.permission:analytics:read')->name('analytics.retention');

    Route::get('/invoices', [BillingController::class, 'invoices'])->middleware('billing.permission:invoices:read')->name('billing.invoices');

    // --- Invoice lifecycle actions (Wave 3): manual create + void/refund/mark-paid/resend.
    // Reads carry `invoices:read`; every money-moving write carries `invoices:refund` (the
    // manifest's invoice-mutation authority) and is guarded server-side in InvoiceOperations.
    // `/invoices/new` is declared before `/invoices/{invoice}` so the static segment is never
    // captured by the model binding.
    Route::get('/invoices/new', [InvoiceOpsController::class, 'create'])->middleware('billing.permission:invoices:refund')->name('billing.invoices.create');
    Route::post('/invoices', [InvoiceOpsController::class, 'store'])->middleware('billing.permission:invoices:refund')->name('billing.invoices.store');

    // Credit notes (Wave 3): the legal record surface for refunds/adjustments, read-only
    // (issued only by the engine). `/credit-notes/{creditNote}` binds by id.
    Route::get('/credit-notes', [CreditNoteController::class, 'index'])->middleware('billing.permission:invoices:read')->name('billing.credit-notes');
    Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show'])->middleware('billing.permission:invoices:read')->name('billing.credit-notes.show');

    Route::get('/invoices/{invoice}', [BillingController::class, 'invoice'])->middleware('billing.permission:invoices:read')->name('billing.invoices.show');
    Route::get('/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->middleware('billing.permission:invoices:read')->name('billing.invoices.pdf');

    Route::post('/invoices/{invoice}/void', [InvoiceOpsController::class, 'void'])->middleware('billing.permission:invoices:refund')->name('billing.invoices.void');
    Route::post('/invoices/{invoice}/refund', [InvoiceOpsController::class, 'refund'])->middleware('billing.permission:invoices:refund')->name('billing.invoices.refund');
    Route::post('/invoices/{invoice}/mark-paid', [InvoiceOpsController::class, 'markPaid'])->middleware('billing.permission:invoices:refund')->name('billing.invoices.mark-paid');
    Route::post('/invoices/{invoice}/resend', [InvoiceOpsController::class, 'resend'])->middleware('billing.permission:invoices:refund')->name('billing.invoices.resend');

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
    });

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
    Route::get('/customers/{organization}', [BillingController::class, 'customer'])->middleware('billing.permission:customers:read')->name('billing.customers.show');

    // --- On-prem licensing (issuer console) ---
    // Gated on the `licenses` console-kit feature (presence gate): the routes 404 when
    // the feature is off. The base registers it always-on, so it is reachable here.
    Route::middleware('console.feature:licenses')->group(function (): void {
        Route::get('/licenses', [LicenseController::class, 'index'])->middleware('billing.permission:licenses:read')->name('billing.licenses');
        Route::get('/licenses/distribution', [LicenseController::class, 'settings'])->middleware('billing.permission:licenses:read')->name('billing.licenses.settings');
        Route::post('/licenses', [LicenseController::class, 'issue'])->middleware('billing.permission:licenses:issue')->name('billing.licenses.issue');
        Route::post('/licenses/{id}/renew', [LicenseController::class, 'renew'])->middleware('billing.permission:licenses:issue')->name('billing.licenses.renew');
        Route::post('/licenses/{id}/revoke', [LicenseController::class, 'revoke'])->middleware('billing.permission:licenses:revoke')->name('billing.licenses.revoke');
    });

    Route::get('/settings', [BillingController::class, 'settings'])->middleware('billing.permission:settings:read')->name('billing.settings');
});
