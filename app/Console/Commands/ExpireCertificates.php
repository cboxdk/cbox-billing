<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Tax\Exemptions\ExemptionStatus;
use App\Models\TaxExemptionCertificate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Housekeeping that flips past-expiry certificates to `expired`. A certificate that is
 * `pending` or `verified` but whose `expires_at` is in the past no longer evidences a live
 * exemption, so it is moved to the terminal `expired` state — the console then shows it as
 * lapsed and it can never exempt a future invoice.
 *
 * This is the visible braces to the tax seam's belt: {@see TaxExemptionCertificate::isActiveNow()}
 * already refuses a past-expiry certificate at calculation time, so a late sweep never
 * mis-charges — the command keeps the stored status honest.
 *
 * Registered daily on the scheduler.
 */
class ExpireCertificates extends Command
{
    protected $signature = 'tax:expire-certificates';

    protected $description = 'Mark past-expiry tax exemption certificates as expired.';

    public function handle(): int
    {
        $now = Carbon::now();

        $count = TaxExemptionCertificate::query()
            ->whereIn('status', [ExemptionStatus::Pending->value, ExemptionStatus::Verified->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->update(['status' => ExemptionStatus::Expired->value, 'updated_at' => $now]);

        $this->info(sprintf('Expired %d exemption certificate%s.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
