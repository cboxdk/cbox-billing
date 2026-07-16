<?php

declare(strict_types=1);

use App\Http\Controllers\Api\LicenseActivationController;
use Illuminate\Support\Facades\Route;

/*
 * The optional online activation heartbeat a self-hosted deployment may call to refresh
 * its license + revocation list (`/api/v1/license/activate`). Unauthenticated by design —
 * a self-hosted deployment holds no operator token; the opaque deployment id is the
 * credential, and an unknown one gets a generic 404. Rate-limited so it cannot be probed.
 * Offline installs never call this and must not depend on it.
 */
Route::get('license/activate', LicenseActivationController::class)
    ->middleware('throttle:30,1')
    ->name('license.activate');
