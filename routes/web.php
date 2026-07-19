<?php

declare(strict_types=1);

use App\Http\Controllers\AccessGrantController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CustomerOpsController;
use App\Http\Controllers\DunningController;
use App\Http\Controllers\InvoiceOpsController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\MeterController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanCreditGrantController;
use App\Http\Controllers\PlanEntitlementController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RetentionController;
use App\Http\Controllers\SeatController;
use App\Http\Controllers\SellerEntityController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubscriptionOpsController;
use App\Http\Controllers\TestModeController;
use App\Http\Controllers\WalletController;
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
Route::middleware(['auth.cbox', 'billing.operator', 'billing.mode'])->group(function (): void {
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

    // --- Revenue analytics (engine Reporting module) ---
    Route::get('/analytics/revenue', [AnalyticsController::class, 'revenue'])->middleware('billing.permission:analytics:read')->name('analytics.revenue');
    Route::get('/analytics/retention', [AnalyticsController::class, 'retention'])->middleware('billing.permission:analytics:read')->name('analytics.retention');

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

    // Access grants (Wave 4): a read-only viewer over the RBAC mirror the provisioning
    // webhooks maintain (Cbox ID owns assignment). `/access-grants` before `{organization}`.
    Route::get('/access-grants', [AccessGrantController::class, 'index'])->middleware('billing.permission:customers:read')->name('billing.access-grants');

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

    // Wallet / credits (Wave 3): an operator credit adjustment (promotional/goodwill grant
    // or correcting debit) through the engine wallet with an audit row. Gated on the dedicated
    // `wallet:manage` slug (Wave 4 split from `customers:manage`).
    Route::post('/customers/{organization}/wallet/adjust', [WalletController::class, 'adjust'])->middleware('billing.permission:wallet:manage')->name('billing.customers.wallet.adjust');

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

    Route::get('/settings/api-tokens/new', [ApiTokenController::class, 'create'])->middleware('billing.permission:settings:manage')->name('billing.settings.tokens.create');
    Route::post('/settings/api-tokens', [ApiTokenController::class, 'store'])->middleware('billing.permission:settings:manage')->name('billing.settings.tokens.store');
    Route::post('/settings/api-tokens/{apiToken}/revoke', [ApiTokenController::class, 'revoke'])->middleware('billing.permission:settings:manage')->name('billing.settings.tokens.revoke');

    Route::get('/settings/gateways', [SettingsController::class, 'gateways'])->middleware('billing.permission:settings:read')->name('billing.settings.gateways');

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
});
