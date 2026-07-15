<?php

declare(strict_types=1);

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Inbound payment-settlement webhooks. Public (no bearer token) — authenticity is proved
 * by the gateway signature the bound WebhookVerifier checks, not by an API token. The
 * ingest is exactly-once, so a re-delivery is a safe no-op.
 */
Route::post('webhooks/{gateway}', WebhookController::class)->name('webhooks.receive');
