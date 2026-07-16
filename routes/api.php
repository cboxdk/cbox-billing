<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CommitController;
use App\Http\Controllers\Api\EntitlementController;
use App\Http\Controllers\Api\LeaseController;
use App\Http\Controllers\Api\Management\InvoiceController;
use App\Http\Controllers\Api\Management\PlanController;
use App\Http\Controllers\Api\Management\SubscriptionController;
use App\Http\Controllers\Api\Management\UsageController as UsageSummaryController;
use App\Http\Controllers\Api\ReserveController;
use App\Http\Controllers\Api\UsageController;
use Illuminate\Support\Facades\Route;

/*
 * The enforcement HTTP API (`/api/v1`) the `cboxdk/laravel-billing-client` SDK consumes.
 * Token-authenticated (see the `api.token` middleware); each route is a thin controller
 * over an engine-backed service.
 */
Route::post('leases', LeaseController::class)->name('leases.create');
Route::post('usage', UsageController::class)->name('usage.ingest');
Route::post('reserve', ReserveController::class)->name('reserve');
Route::post('commit', CommitController::class)->name('commit');
Route::get('entitlements/{org}', EntitlementController::class)->name('entitlements.show');

/*
 * The self-service management API (`/api/v1`) the `cboxdk/laravel-billing-client` SDK's
 * management surface and the hosted portal drive. Same token auth and per-org scope as
 * the enforcement API; thin controllers over task #41's lifecycle services and the engine.
 */
Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

Route::get('subscriptions/{org}', [SubscriptionController::class, 'show'])->name('subscriptions.show');
Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
Route::post('subscriptions/{org}/preview', [SubscriptionController::class, 'preview'])->name('subscriptions.preview');
Route::post('subscriptions/{org}/change', [SubscriptionController::class, 'change'])->name('subscriptions.change');
Route::post('subscriptions/{org}/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');

/*
 * Subscription-management depth (ADR-0012): pause/resume, seat-quantity changes with
 * preview-equals-charge proration, and aligned/independent add-ons — thin controllers
 * over the engine lifecycle + the app's depth service.
 */
Route::post('subscriptions/{org}/pause', [SubscriptionController::class, 'pause'])->name('subscriptions.pause');
Route::post('subscriptions/{org}/resume', [SubscriptionController::class, 'resume'])->name('subscriptions.resume');
Route::post('subscriptions/{org}/quantity', [SubscriptionController::class, 'quantity'])->name('subscriptions.quantity');
Route::post('subscriptions/{org}/addons', [SubscriptionController::class, 'addAddOn'])->name('subscriptions.addons.add');
Route::delete('subscriptions/{org}/addons/{key}', [SubscriptionController::class, 'removeAddOn'])->name('subscriptions.addons.remove');

Route::get('usage/{org}', UsageSummaryController::class)->name('usage.summary');
Route::get('invoices/{org}', InvoiceController::class)->name('invoices.index');
