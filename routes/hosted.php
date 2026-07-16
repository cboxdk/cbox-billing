<?php

declare(strict_types=1);

use App\Http\Controllers\Hosted\CheckoutController;
use App\Http\Controllers\Hosted\PortalController;
use Illuminate\Support\Facades\Route;

/*
 * The hosted checkout + customer-portal pages (ADR-0009 Path A). These are NOT behind the
 * provider `auth.cbox` gate — the opaque session token in the URL is the whole
 * authorization, and an invalid/expired token 404s. The pages render on the design-system
 * tokens; their JSON action endpoints create the gateway intent, poll the session status,
 * and drive plan changes / payment-method updates through the same lifecycle services the
 * management API uses.
 */
Route::prefix('billing')->name('hosted.')->group(function (): void {
    Route::get('checkout/{token}', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('checkout/{token}/intent', [CheckoutController::class, 'intent'])->name('checkout.intent');
    Route::get('checkout/{token}/status', [CheckoutController::class, 'status'])->name('checkout.status');

    Route::get('portal/{token}', [PortalController::class, 'show'])->name('portal.show');
    Route::post('portal/{token}/preview', [PortalController::class, 'preview'])->name('portal.preview');
    Route::post('portal/{token}/change', [PortalController::class, 'change'])->name('portal.change');
    Route::post('portal/{token}/cancel', [PortalController::class, 'cancel'])->name('portal.cancel');
    Route::post('portal/{token}/setup-intent', [PortalController::class, 'setupIntent'])->name('portal.setup-intent');
    Route::post('portal/{token}/payment-method', [PortalController::class, 'paymentMethod'])->name('portal.payment-method');
});
