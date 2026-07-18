<?php

declare(strict_types=1);

use App\Http\Controllers\WebhookController;
use Cbox\Id\Client\Http\WebhookController as CboxIdWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Inbound Cbox ID PROVISIONING webhooks (member/role/directory/org events). Public but
 * HMAC-verified by the SDK controller against CBOX_ID_WEBHOOK_SECRET (deny-by-default: no
 * secret ⇒ every payload refused). Registered BEFORE the `{gateway}` wildcard below so a
 * POST to `/webhooks/cbox-id` is never swallowed by the payment-settlement route — the two
 * paths stay disjoint. The SDK verifies + acks here and runs the app's handlers on a queued
 * job (see CboxIdWebhookServiceProvider), so a slow handler never stalls the ack.
 */
Route::post('webhooks/cbox-id', CboxIdWebhookController::class)->name('webhooks.cbox-id');

/*
 * Inbound payment-settlement webhooks. Public (no bearer token) — authenticity is proved
 * by the gateway signature the bound WebhookVerifier checks, not by an API token. The
 * ingest is exactly-once, so a re-delivery is a safe no-op.
 */
Route::post('webhooks/{gateway}', WebhookController::class)->name('webhooks.receive');
