<?php

declare(strict_types=1);

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\RetentionController;
use Illuminate\Support\Facades\Route;

// --- Authentication (Cbox ID as OIDC provider) ---
Route::get('/login', [AuthController::class, 'login'])->name('login');
Route::get('/auth/redirect', [AuthController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/callback', [AuthController::class, 'callback'])->name('auth.callback');
Route::post('/auth/demo', [AuthController::class, 'demo'])->name('auth.demo');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// --- The provider console (requires an authenticated Cbox ID session) ---
Route::middleware('auth.cbox')->group(function (): void {
    Route::get('/', [BillingController::class, 'dashboard'])->name('billing.dashboard');

    Route::get('/subscriptions', [BillingController::class, 'subscriptions'])->name('billing.subscriptions');
    Route::get('/subscriptions/dunning', [BillingController::class, 'dunning'])->name('billing.subscriptions.dunning');
    Route::get('/subscriptions/{subscription}', [BillingController::class, 'subscription'])->name('billing.subscriptions.show');

    // Retention actions (App-A ManagesRetention): cancel-with-reason / pause / reactivate.
    Route::post('/subscriptions/{subscription}/cancel', [RetentionController::class, 'cancel'])->name('billing.subscriptions.cancel');
    Route::post('/subscriptions/{subscription}/reactivate', [RetentionController::class, 'reactivate'])->name('billing.subscriptions.reactivate');

    // --- Revenue analytics (engine Reporting module) ---
    Route::get('/analytics/revenue', [AnalyticsController::class, 'revenue'])->name('analytics.revenue');
    Route::get('/analytics/retention', [AnalyticsController::class, 'retention'])->name('analytics.retention');

    Route::get('/invoices', [BillingController::class, 'invoices'])->name('billing.invoices');
    Route::get('/invoices/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoices.show');
    Route::get('/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->name('billing.invoices.pdf');

    Route::get('/usage', [BillingController::class, 'usage'])->name('billing.usage');
    Route::get('/catalog', [BillingController::class, 'catalog'])->name('billing.catalog');
    Route::get('/pricing', [BillingController::class, 'pricing'])->name('billing.pricing');

    // Catalog authoring: create/edit a plan price and (for tiered models) its tier table.
    Route::get('/catalog/prices/new', [CatalogController::class, 'create'])->name('billing.catalog.prices.create');
    Route::post('/catalog/prices', [CatalogController::class, 'store'])->name('billing.catalog.prices.store');
    Route::get('/catalog/prices/{price}/edit', [CatalogController::class, 'edit'])->name('billing.catalog.prices.edit');
    Route::put('/catalog/prices/{price}', [CatalogController::class, 'update'])->name('billing.catalog.prices.update');

    // Plan retirement authoring (ADR-0016): mark a plan retiring / un-retire it.
    Route::post('/catalog/plans/{plan}/retire', [CatalogController::class, 'retire'])->name('billing.catalog.plans.retire');
    Route::post('/catalog/plans/{plan}/unretire', [CatalogController::class, 'unretire'])->name('billing.catalog.plans.unretire');

    Route::get('/customers', [BillingController::class, 'customers'])->name('billing.customers');
    Route::get('/customers/{organization}', [BillingController::class, 'customer'])->name('billing.customers.show');

    // --- On-prem licensing (issuer console) ---
    // Gated on the `licenses` console-kit feature (presence gate): the routes 404 when
    // the feature is off. The base registers it always-on, so it is reachable here.
    Route::middleware('console.feature:licenses')->group(function (): void {
        Route::get('/licenses', [LicenseController::class, 'index'])->name('billing.licenses');
        Route::get('/licenses/distribution', [LicenseController::class, 'settings'])->name('billing.licenses.settings');
        Route::post('/licenses', [LicenseController::class, 'issue'])->name('billing.licenses.issue');
        Route::post('/licenses/{id}/renew', [LicenseController::class, 'renew'])->name('billing.licenses.renew');
        Route::post('/licenses/{id}/revoke', [LicenseController::class, 'revoke'])->name('billing.licenses.revoke');
    });

    Route::get('/settings', [BillingController::class, 'settings'])->name('billing.settings');
});
