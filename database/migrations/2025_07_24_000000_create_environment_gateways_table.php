<?php

declare(strict_types=1);

use App\Models\Environment;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-environment payment-gateway credentials. Until now gateway keys were GLOBAL environment
 * variables (`STRIPE_SECRET`, …) shared by every plane — wrong for sandbox/live separation,
 * where a throwaway sandbox must never be able to reach the real, money-moving gateway account.
 *
 * Each row binds one gateway's credentials to one {@see Environment} (plane): the
 * secret / publishable / webhook-signing secret, stored ENCRYPTED at rest (Laravel `encrypted`
 * cast — the app key is the only thing that decrypts them), and an `active` flag. The bound
 * {@see PaymentGateway} resolves the CURRENT environment's row so
 * every plane charges through its own account.
 *
 * BC: an environment with NO active row falls back to the legacy global env-var keys when it is
 * production, and to the manual/test gateway otherwise — so existing single-plane deployments
 * keep working unchanged with nothing in this table.
 *
 * The `(environment, gateway)` unique makes the resolve a single-row lookup and stops two rows
 * from claiming the same gateway in one plane. This is CONFIG (an operator setup), not tenant
 * data: a sandbox RESET keeps it; an environment DESTROY deletes it with the plane.
 *
 * DEPLOY NOTE: additive new table; nothing to backfill (an empty table = the legacy env-var
 * behaviour everywhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('environment')->index();
            $table->string('gateway');                 // e.g. stripe
            $table->text('secret');                    // encrypted at rest
            $table->text('publishable')->nullable();   // encrypted at rest
            $table->text('webhook_secret')->nullable(); // encrypted at rest
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['environment', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_gateways');
    }
};
