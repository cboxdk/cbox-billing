<?php

declare(strict_types=1);

use Database\Seeders\EnvironmentSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-class, named billing ENVIRONMENTS — the generalisation of the binary test/live
 * `livemode` plane. Each row is an addressable plane: production (real, protected, live gateway
 * keys) and one or more sandboxes (isolated, disposable, fake gateway). Every plane-scoped table
 * moves its partition from `livemode` (bool) to this environment's stable `key` (see the
 * companion generalise migration); this table is the registry those keys reference.
 *
 * Billing owns this table (billing-internal CRUD) so a later wave can clone/destroy sandboxes.
 * `cbox_id_environment` is an OPTIONAL mapping to a Cbox ID environment — nullable, never
 * required. `protected` guards production against deletion.
 *
 * DEPLOY NOTE: additive new table; seeded (production + sandbox) by {@see EnvironmentSeeder}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('type')->index();            // production | sandbox
            $table->boolean('protected')->default(false);
            $table->string('gateway_key_mode');         // live | test
            $table->string('cbox_id_environment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environments');
    }
};
