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

// --- The app (requires an authenticated Cbox ID session) ---
Route::middleware('auth.cbox')->group(function (): void {
    Route::get('/', [BillingController::class, 'dashboard'])->name('billing.dashboard');
    Route::get('/subscriptions', [BillingController::class, 'subscriptions'])->name('billing.subscriptions');
    Route::get('/invoices', [BillingController::class, 'invoices'])->name('billing.invoices');

    // Not-yet-built areas render the empty-state screen within the shell.
    Route::get('/{area}', [BillingController::class, 'section'])
        ->whereIn('area', ['usage', 'catalog', 'customers', 'settings'])
        ->name('billing.section');
});
