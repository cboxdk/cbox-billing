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
    Route::get('portal/{token}/invoices/{invoice}/pdf', [PortalController::class, 'invoicePdf'])->name('portal.invoice-pdf');
    Route::get('portal/{token}/credit-notes/{creditNote}/pdf', [PortalController::class, 'creditNotePdf'])->name('portal.credit-note-pdf');
    Route::post('portal/{token}/preview', [PortalController::class, 'preview'])->name('portal.preview');
    Route::post('portal/{token}/change', [PortalController::class, 'change'])->name('portal.change');
    Route::post('portal/{token}/cancel', [PortalController::class, 'cancel'])->name('portal.cancel');
    Route::post('portal/{token}/retirement/successor', [PortalController::class, 'chooseSuccessor'])->name('portal.retirement.successor');
    Route::post('portal/{token}/setup-intent', [PortalController::class, 'setupIntent'])->name('portal.setup-intent');
    Route::post('portal/{token}/payment-method', [PortalController::class, 'paymentMethod'])->name('portal.payment-method');
    Route::post('portal/{token}/payment-method/default', [PortalController::class, 'setDefaultMethod'])->name('portal.payment-method.default');
    Route::post('portal/{token}/payment-method/remove', [PortalController::class, 'removeMethod'])->name('portal.payment-method.remove');

    // Self-serve seats: preview the prorated buy/release, apply it, and assign/unassign the
    // org's own members to Full seats (cap-enforced in the service).
    Route::post('portal/{token}/seats/preview', [PortalController::class, 'seatPreview'])->name('portal.seats.preview');
    Route::post('portal/{token}/seats', [PortalController::class, 'setSeats'])->name('portal.seats.set');
    Route::post('portal/{token}/seats/assign', [PortalController::class, 'assignSeat'])->name('portal.seats.assign');
    Route::post('portal/{token}/seats/unassign', [PortalController::class, 'unassignSeat'])->name('portal.seats.unassign');

    // Optional-notification opt-in/out (mandatory/legal mails are never togglable here).
    Route::post('portal/{token}/notifications', [PortalController::class, 'updateNotifications'])->name('portal.notifications');

    // Customer self-serve tax exemption: upload a certificate (lands pending for operator
    // review; the customer can never self-verify). Scoped to the token's organization.
    Route::post('portal/{token}/exemptions', [PortalController::class, 'uploadExemption'])->name('portal.exemptions');
});
