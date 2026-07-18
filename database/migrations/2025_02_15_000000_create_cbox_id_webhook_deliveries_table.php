<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-sight dedup for Cbox ID provisioning webhooks. Cbox ID's verified envelope
 * carries a stable `delivery_id`; the provisioning sync claims it here with a UNIQUE
 * insert in the SAME transaction as the effect (mirror + seat adjustment), so a
 * re-delivery or a crash mid-apply is a safe no-op — the handler runs exactly once
 * per delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbox_id_webhook_deliveries', function (Blueprint $table): void {
            $table->string('delivery_id')->primary();
            $table->string('event_type');
            $table->string('organization_id')->nullable();
            $table->timestamp('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbox_id_webhook_deliveries');
    }
};
