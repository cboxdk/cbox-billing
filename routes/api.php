<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CommitController;
use App\Http\Controllers\Api\EntitlementController;
use App\Http\Controllers\Api\LeaseController;
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
