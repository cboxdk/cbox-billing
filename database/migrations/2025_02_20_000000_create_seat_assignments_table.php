<?php

declare(strict_types=1);

use App\Models\CboxIdAccessGrant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEAT ASSIGNMENTS: which purchased Full seat is given to which specific member (subject).
 *
 * Purchased Full seats are the serving subscription's seat quantity — the ONLY billing
 * driver. Each row here hands one of those purchased seats to a member drawn from the
 * eligibility mirror ({@see CboxIdAccessGrant}). The invariant the seat
 * manager enforces is `assigned count ≤ purchased seats`: assigning with no free seat is
 * refused, and releasing a purchased seat below the assigned count is refused.
 *
 * A member in the mirror WITHOUT a row here is Light (counted, displayed, never billed).
 * `source` records whether an operator assigned the seat (`manual`) or the auto-assign
 * mode did (`auto`) — only an `auto` seat is ever auto-released on a role drop. One
 * assignment per (organization, subject); additive, no destructive change to any table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id');
            $table->string('subject');
            $table->string('source')->default('manual');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'subject']);
            $table->index('organization_id');
            $table->index('subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_assignments');
    }
};
