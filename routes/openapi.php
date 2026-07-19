<?php

declare(strict_types=1);

use App\Http\Controllers\OpenApiController;
use Illuminate\Support\Facades\Route;

/*
 * The public API reference: the OpenAPI 3.1 contract (YAML source of truth + JSON
 * projection) and a self-contained HTML docs page. No bearer token — the contract is
 * public so an integrator can read it before provisioning a token. Served outside the
 * `/api/v1` token-authed group; the pages read the committed spec files under docs/openapi.
 */
Route::get('api/openapi.yaml', [OpenApiController::class, 'yaml'])->name('openapi.yaml');
Route::get('api/openapi.json', [OpenApiController::class, 'json'])->name('openapi.json');
Route::get('api/docs', [OpenApiController::class, 'docs'])->name('openapi.docs');
