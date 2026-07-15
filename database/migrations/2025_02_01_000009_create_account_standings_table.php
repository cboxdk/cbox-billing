<?php

declare(strict_types=1);

use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable backing for the engine's
 * {@see AccountStanding} seam: one row per flagged
 * account, keyed on the billing account (the `org` identifier). Absence of a row
 * reads as {@see AccountStandingState::Good} — standing
 * records trouble, it is not an allow-list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_standings', function (Blueprint $table): void {
            $table->string('account')->primary();
            $table->string('state');
            $table->string('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_standings');
    }
};
