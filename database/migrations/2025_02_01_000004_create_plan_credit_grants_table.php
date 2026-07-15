<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan's credit grant definition — the (pool, kind, cadence, amount) tuple the
 * engine's wallet issues when the plan is provisioned. `pool` names the wallet pool
 * the grant lands in (e.g. `included`), `kind` is how it is sized (base / per-seat),
 * `cadence` is how often it is issued (once / recurring), and `amount` is the grant
 * size in the denomination's units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_credit_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('pool');
            $table->string('kind');
            $table->string('cadence');
            $table->unsignedBigInteger('amount');
            $table->string('denomination');
            $table->timestamps();

            $table->unique(['plan_id', 'pool']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_credit_grants');
    }
};
