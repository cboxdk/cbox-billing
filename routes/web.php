<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
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
    Route::get('/subscriptions/{subscription}', [BillingController::class, 'subscription'])->name('billing.subscriptions.show');

    Route::get('/invoices', [BillingController::class, 'invoices'])->name('billing.invoices');
    Route::get('/invoices/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoices.show');
    Route::get('/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->name('billing.invoices.pdf');

    Route::get('/usage', [BillingController::class, 'usage'])->name('billing.usage');
    Route::get('/catalog', [BillingController::class, 'catalog'])->name('billing.catalog');
    Route::get('/pricing', [BillingController::class, 'pricing'])->name('billing.pricing');

    Route::get('/customers', [BillingController::class, 'customers'])->name('billing.customers');
    Route::get('/customers/{organization}', [BillingController::class, 'customer'])->name('billing.customers.show');

    Route::get('/settings', [BillingController::class, 'settings'])->name('billing.settings');
});
