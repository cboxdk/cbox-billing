<?php

declare(strict_types=1);

use App\Http\Controllers\PaywallController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
 * The PUBLIC, no-auth embeddable storefront (#57): the pricing table and the paywall. Like the
 * hosted checkout/portal these are NOT behind the provider `auth.cbox` gate — a pricing table is
 * a public marketing surface addressed by its `key` (an unknown/inactive key 404s), and the
 * paywall is addressed by the org + gated capability the app already knows. Every page is
 * self-contained (inline CSS/JS, no external hosts) so it is safe under a strict CSP and drops
 * cleanly into any marketing site.
 */
Route::get('pricing/{key}', [StorefrontController::class, 'show'])->name('storefront.show');
Route::get('pricing/{key}/embed', [StorefrontController::class, 'embed'])->name('storefront.embed');
Route::get('pricing/{key}/embed.js', [StorefrontController::class, 'loader'])->name('storefront.loader');

Route::get('paywall', [PaywallController::class, 'show'])->name('storefront.paywall');
