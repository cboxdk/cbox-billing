<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Environments\PlaneDocumentPrefix;
use App\Models\Environment;
use PHPUnit\Framework\TestCase;

/**
 * The derivation behind plane-distinct legal numbering: production is never rewritten, every other
 * plane gets its own readable marker, the result stays inside the authored `max:40` width, and the
 * whole thing is deterministic and idempotent (a reseed or a backfill never stacks markers).
 */
class PlaneDocumentPrefixTest extends TestCase
{
    public function test_production_is_never_rewritten(): void
    {
        $this->assertSame('CBOX-DK', PlaneDocumentPrefix::for('CBOX-DK', Environment::PRODUCTION));
    }

    public function test_a_sandbox_prefix_carries_the_plane_key(): void
    {
        $this->assertSame('CBOX-DK-CI-CLONE', PlaneDocumentPrefix::for('CBOX-DK', 'ci-clone'));
        $this->assertSame('CBOX-DK-STAGING', PlaneDocumentPrefix::for('CBOX-DK', 'staging'));
    }

    public function test_distinct_planes_never_share_a_prefix(): void
    {
        $this->assertNotSame(
            PlaneDocumentPrefix::for('CBOX-DK', 'ci-one'),
            PlaneDocumentPrefix::for('CBOX-DK', 'ci-two'),
        );
    }

    public function test_the_derivation_is_deterministic_and_idempotent(): void
    {
        $once = PlaneDocumentPrefix::for('CBOX-DK', 'ci-clone');

        $this->assertSame($once, PlaneDocumentPrefix::for('CBOX-DK', 'ci-clone'));
        $this->assertSame($once, PlaneDocumentPrefix::for($once, 'ci-clone'));
    }

    /** A plane key too long to fit degrades to a digest marker rather than a truncated key. */
    public function test_a_long_plane_key_stays_within_the_authored_width_and_stays_distinct(): void
    {
        $long = str_repeat('a', 30);
        $other = str_repeat('a', 29).'b';

        $first = PlaneDocumentPrefix::for('CBOX-DK-ENTITY-NUMBER-ONE', $long);
        $second = PlaneDocumentPrefix::for('CBOX-DK-ENTITY-NUMBER-ONE', $other);

        $this->assertLessThanOrEqual(PlaneDocumentPrefix::MAX, strlen($first));
        $this->assertLessThanOrEqual(PlaneDocumentPrefix::MAX, strlen($second));
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $first);
        $this->assertSame($first, PlaneDocumentPrefix::for($first, $long));
    }

    /** Rebasing (a promoted seller) drops the source plane's marker and applies the target's. */
    public function test_rebase_moves_a_prefix_between_planes(): void
    {
        $this->assertSame('CBOX-DK', PlaneDocumentPrefix::rebase('CBOX-DK-CI-CLONE', 'ci-clone', Environment::PRODUCTION));
        $this->assertSame('CBOX-DK-STAGING', PlaneDocumentPrefix::rebase('CBOX-DK-CI-CLONE', 'ci-clone', 'staging'));
        $this->assertSame('CBOX-DK-STAGING', PlaneDocumentPrefix::rebase('CBOX-DK', Environment::PRODUCTION, 'staging'));
    }
}
