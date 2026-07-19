<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Contracts\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator wallet-adjustment audit log (Wave 3). Every console credit adjustment —
 * a promotional/goodwill grant or a correcting debit — writes through the engine
 * {@see Wallet} AND records an immutable row here: the
 * pool + denomination touched, the signed `amount` (positive = grant, negative =
 * debit), the operator's reason, and who did it. This is the audit surface the wallet
 * ledger cross-links; it never holds a balance (that is always derived from the wallet
 * lots), only the record of the manual movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('pool_key');
            $table->string('denomination_code');
            $table->boolean('denomination_is_money')->default(false);
            $table->bigInteger('amount');
            $table->string('direction');
            $table->string('reason');
            $table->string('actor')->nullable();
            $table->string('grant_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_adjustments');
    }
};
