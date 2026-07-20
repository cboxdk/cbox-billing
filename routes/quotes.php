<?php

declare(strict_types=1);

use App\Http\Controllers\OrderFormController;
use Illuminate\Support\Facades\Route;

/*
 * The PUBLIC, no-auth hosted order form (CPQ Wave 5): the seller-branded, self-contained
 * (inline CSS/JS, no external hosts — CSP-safe) `/quote/{token}` page a customer accepts or
 * declines. Like the hosted checkout/portal these are NOT behind the provider `auth.cbox` gate —
 * the opaque order-form token in the URL is the whole authorization, and an unknown/wrong token
 * 404s (cross-quote isolation). The accept/decline actions run on the `web` group so the session
 * CSRF token protects them; acceptance captures the e-signature-by-acceptance and provisions the
 * subscription idempotently.
 */
Route::get('quote/{token}', [OrderFormController::class, 'show'])->name('quote.show');
Route::post('quote/{token}/accept', [OrderFormController::class, 'accept'])->name('quote.accept');
Route::post('quote/{token}/decline', [OrderFormController::class, 'decline'])->name('quote.decline');
