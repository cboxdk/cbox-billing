<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Licensing\LicenseReport;
use App\Billing\Reporting\SettingsReport;
use App\Models\SellerEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The navigation composer runs on EVERY authenticated console render, and was measured at 42–67
 * uncached queries — roughly two-thirds of the whole query budget for a page whose own list was
 * about 23.
 *
 * This asserts the two costs that SCALED, since a fixed overhead is a much smaller problem than
 * one that grows with the operator's data:
 *
 *  - `taxRegistrations()` re-called `sellers()`, so the entire seller path — a query plus a
 *    catalog lookup per row — executed twice per page.
 *  - the licence badge called `LicenseReport::counts()`, which reads every issued licence plus the
 *    whole revocation set into PHP to classify each one, when the badge needs only a total.
 *
 * Measured at the read model rather than through an HTTP request on purpose: an earlier version of
 * this test hit a console route and passed even with the memo removed, because feature gating
 * meant that page never reached the seller path. It was asserting nothing.
 */
class NavigationQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function sellers(int $count, string $prefix): void
    {
        for ($i = 0; $i < $count; $i++) {
            SellerEntity::query()->create([
                'id' => $prefix.'_seller_'.$i,
                'legal_name' => 'Seller '.$prefix.' '.$i,
                'registration_number' => 'DK'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'establishment' => 'DK',
                'currency' => 'DKK',
                'invoice_prefix' => strtoupper($prefix).$i,
            ]);
        }
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Both are read in the same render, and the second used to re-walk the first. The memo is
     * request-scoped, so a fresh instance is resolved per measurement — exactly as a request does.
     */
    public function test_the_seller_path_is_walked_once_per_request_not_twice(): void
    {
        $this->sellers(4, 'a');

        // Warm the connection first: the very first query on a fresh SQLite connection also pays
        // schema introspection, which would otherwise land entirely on whichever measurement ran
        // first and mask the difference being asserted.
        app(SettingsReport::class)->sellers();

        $sellersOnly = $this->queriesFor(static function (): void {
            app(SettingsReport::class)->sellers();
        });

        $report = app(SettingsReport::class);

        $both = $this->queriesFor(static function () use ($report): void {
            $report->sellers();
            $report->taxRegistrations();
        });

        $this->assertSame(
            $sellersOnly,
            $both,
            "Reading sellers() and taxRegistrations() together cost {$both} queries, but sellers() "
            ."alone costs {$sellersOnly} — taxRegistrations() is re-walking the seller path instead "
            .'of reusing the memo, so the whole path runs twice on every console render.',
        );
    }

    /** The licence badge must be a COUNT, not a classify-every-row read. */
    public function test_the_licence_badge_is_a_single_count(): void
    {
        $report = app(LicenseReport::class);

        $queries = $this->queriesFor(static function () use ($report): void {
            $report->total();
        });

        $this->assertLessThanOrEqual(
            1,
            $queries,
            'The navigation licence badge must cost one COUNT; counts() reads every issued licence '
            .'and the full revocation set to classify each one.',
        );
    }
}
