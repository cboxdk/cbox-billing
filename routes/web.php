<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\RetentionController;
use App\Http\Controllers\SeatController;
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
    Route::get('/subscriptions/{subscription}', [BillingController::class, 'subscription'])->middleware('billing.permission:subscriptions:read')->name('billing.subscriptions.show');

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
    Route::get('/invoices/{invoice}', [BillingController::class, 'invoice'])->middleware('billing.permission:invoices:read')->name('billing.invoices.show');
    Route::get('/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->middleware('billing.permission:invoices:read')->name('billing.invoices.pdf');

    Route::get('/usage', [BillingController::class, 'usage'])->middleware('billing.permission:usage:read')->name('billing.usage');
    Route::get('/catalog', [BillingController::class, 'catalog'])->middleware('billing.permission:catalog:read')->name('billing.catalog');
    Route::get('/pricing', [BillingController::class, 'pricing'])->middleware('billing.permission:catalog:read')->name('billing.pricing');

    // Catalog authoring: create/edit a plan price and (for tiered models) its tier table.
    Route::get('/catalog/prices/new', [CatalogController::class, 'create'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.create');
    Route::post('/catalog/prices', [CatalogController::class, 'store'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.store');
    Route::get('/catalog/prices/{price}/edit', [CatalogController::class, 'edit'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.edit');
    Route::put('/catalog/prices/{price}', [CatalogController::class, 'update'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.prices.update');

    // Plan retirement authoring (ADR-0016): mark a plan retiring / un-retire it.
    Route::post('/catalog/plans/{plan}/retire', [CatalogController::class, 'retire'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.plans.retire');
    Route::post('/catalog/plans/{plan}/unretire', [CatalogController::class, 'unretire'])->middleware('billing.permission:catalog:manage')->name('billing.catalog.plans.unretire');

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
