<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CommitController;
use App\Http\Controllers\Api\EntitlementController;
use App\Http\Controllers\Api\FeatureEntitlementController;
use App\Http\Controllers\Api\LeaseController;
use App\Http\Controllers\Api\Management\CheckoutSessionController;
use App\Http\Controllers\Api\Management\InvoiceController;
use App\Http\Controllers\Api\Management\LicenseController;
use App\Http\Controllers\Api\Management\OrganizationController;
use App\Http\Controllers\Api\Management\PaymentIntentController;
use App\Http\Controllers\Api\Management\PaymentMethodController;
use App\Http\Controllers\Api\Management\PlanController;
use App\Http\Controllers\Api\Management\PortalSessionController;
use App\Http\Controllers\Api\Management\SeatController;
use App\Http\Controllers\Api\Management\SetupIntentController;
use App\Http\Controllers\Api\Management\SubscriptionController;
use App\Http\Controllers\Api\Management\TestClockController;
use App\Http\Controllers\Api\Management\UsageController as UsageSummaryController;
use App\Http\Controllers\Api\ReserveController;
use App\Http\Controllers\Api\UsageController;
use Illuminate\Support\Facades\Route;

/*
 * The enforcement HTTP API (`/api/v1`) the `cboxdk/laravel-billing-client` SDK consumes.
 * Token-authenticated (see the `api.token` middleware); each route is a thin controller
 * over an engine-backed service. Per-token rate limit: the HOT path
 * (`throttle:cbox-enforcement`, the higher tier) — it runs on every metered operation.
 */
Route::middleware('throttle:cbox-enforcement')->group(function (): void {
    Route::post('leases', LeaseController::class)->name('leases.create');
    Route::post('usage', UsageController::class)->name('usage.ingest');
    Route::post('reserve', ReserveController::class)->name('reserve');
    Route::post('commit', CommitController::class)->name('commit');
    Route::get('entitlements/{org}', EntitlementController::class)->name('entitlements.show');

    /*
     * The boolean / non-metered feature-entitlements sibling of `/entitlements/{org}` (product
     * gating). Same token auth + per-org scope + hot-path throttle; each is a thin controller
     * over the feature resolver. `{key}` is a feature slug (dots allowed, no slashes).
     */
    Route::get('entitlements/{org}/features', [FeatureEntitlementController::class, 'index'])->name('entitlements.features.index');
    Route::get('entitlements/{org}/features/{key}', [FeatureEntitlementController::class, 'show'])
        ->where('key', '[A-Za-z0-9._-]+')
        ->name('entitlements.features.show');
});

/*
 * The self-service management API (`/api/v1`) the `cboxdk/laravel-billing-client` SDK's
 * management surface and the hosted portal drive. Same token auth and per-org scope as
 * the enforcement API; thin controllers over task #41's lifecycle services and the engine.
 * Per-token rate limit: the LOWER tier (`throttle:cbox-management`) — human-paced, mutating
 * calls. The mutating writes additionally honour an `Idempotency-Key` header (see the
 * `idempotency` middleware) so a retried subscribe/issue can't double-apply.
 */
Route::middleware(['throttle:cbox-management', 'billing.audit'])->group(function (): void {
    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

    // Merchant platforms provision the orgs they bill for on demand (idempotent upsert).
    Route::put('organizations/{org}', [OrganizationController::class, 'upsert'])->name('organizations.upsert');

    Route::get('subscriptions/{org}', [SubscriptionController::class, 'show'])->name('subscriptions.show');
    Route::post('subscriptions', [SubscriptionController::class, 'store'])->middleware('idempotency')->name('subscriptions.store');
    Route::post('subscriptions/{org}/preview', [SubscriptionController::class, 'preview'])->name('subscriptions.preview');
    Route::post('subscriptions/{org}/change', [SubscriptionController::class, 'change'])->middleware('idempotency')->name('subscriptions.change');
    Route::post('subscriptions/{org}/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
    Route::post('subscriptions/{org}/reactivate', [SubscriptionController::class, 'reactivate'])->name('subscriptions.reactivate');

    /*
     * Subscription-management depth (ADR-0012): pause/resume, seat-quantity changes with
     * preview-equals-charge proration, and aligned/independent add-ons — thin controllers
     * over the engine lifecycle + the app's depth service. The quantity + add-on writes are
     * idempotency-keyed (a retried charge must not re-prorate).
     */
    Route::post('subscriptions/{org}/pause', [SubscriptionController::class, 'pause'])->name('subscriptions.pause');
    Route::post('subscriptions/{org}/resume', [SubscriptionController::class, 'resume'])->name('subscriptions.resume');
    Route::post('subscriptions/{org}/quantity', [SubscriptionController::class, 'quantity'])->middleware('idempotency')->name('subscriptions.quantity');
    Route::post('subscriptions/{org}/addons', [SubscriptionController::class, 'addAddOn'])->middleware('idempotency')->name('subscriptions.addons.add');
    Route::delete('subscriptions/{org}/addons/{key}', [SubscriptionController::class, 'removeAddOn'])->name('subscriptions.addons.remove');

    /*
     * Seats (purchased + explicitly-assigned model). Purchased Full seats ARE the
     * subscription quantity and the only billing driver: setting them buys/releases seats
     * through the engine's prorated changeQuantity (idempotency-keyed — a retried buy must
     * not re-prorate). Assignment hands a purchased seat to an eligible member (Full);
     * unassigning frees it (Light). Same per-org token scope as the rest of the surface; the
     * invariant assigned ≤ purchased is enforced server-side (a refusal is a 409).
     */
    Route::get('subscriptions/{org}/seats', [SeatController::class, 'show'])->name('subscriptions.seats.show');
    Route::post('subscriptions/{org}/seats', [SeatController::class, 'setPurchased'])->middleware('idempotency')->name('subscriptions.seats.set');
    Route::post('subscriptions/{org}/seats/assign', [SeatController::class, 'assign'])->name('subscriptions.seats.assign');
    Route::post('subscriptions/{org}/seats/unassign', [SeatController::class, 'unassign'])->name('subscriptions.seats.unassign');

    Route::get('usage/{org}', UsageSummaryController::class)->name('usage.summary');
    Route::get('invoices/{org}', InvoiceController::class)->name('invoices.index');

    /*
     * Hosted checkout + customer portal (ADR-0009 Path A). Same token auth and per-org scope
     * as the rest of the management API; each returns the `{url}` of a hosted page keyed by an
     * opaque, expiring session token (the URL — not the provider auth gate — authorizes it).
     */
    Route::post('checkout-sessions', CheckoutSessionController::class)->name('checkout-sessions.create');
    Route::post('portal-sessions', PortalSessionController::class)->name('portal-sessions.create');

    /*
     * Embedded-intent API (ADR-0009 Path B): a product mounts the gateway's own element and
     * confirms client-side against the client secret these return. Same token auth and per-org
     * scope; each is a thin controller over the bound PaymentGateway and the gateway-customer
     * mapping (the gateway customer id — never the raw org id — is the account on every intent).
     */
    Route::post('setup-intents', SetupIntentController::class)->name('setup-intents.create');
    Route::post('payment-intents', PaymentIntentController::class)->name('payment-intents.create');
    Route::get('payment-methods/{org}', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
    Route::post('payment-methods/{org}/default', [PaymentMethodController::class, 'setDefault'])->name('payment-methods.default');
    Route::delete('payment-methods/{org}/{id}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');

    /*
     * On-prem license management (operator-authed). Issue a signed, offline-verifiable license
     * for a customer + licensable plan, renew it (reissue with an extended window), and revoke
     * it (add to the signed revocation list). Thin controllers over the licensing service; the
     * self-hosted deployment verifies the artifact offline — the activation heartbeat is the
     * separate, unauthenticated refresh path. Issue is idempotency-keyed.
     */
    Route::post('licenses', [LicenseController::class, 'store'])->middleware('idempotency')->name('licenses.store');
    Route::post('licenses/{id}/renew', [LicenseController::class, 'renew'])->name('licenses.renew');
    Route::post('licenses/{id}/revoke', [LicenseController::class, 'revoke'])->name('licenses.revoke');

    /*
     * Test clock advance (sandbox only): fast-forward a test clock's virtual time and run the
     * due billing logic for its bound test subscriptions. Restricted to a test-mode token
     * (the controller refuses a live credential), so it can never touch live data.
     */
    Route::post('test/clocks/{id}/advance', [TestClockController::class, 'advance'])->name('test.clocks.advance');
});
