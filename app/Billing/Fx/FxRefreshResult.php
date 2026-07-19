<?php

declare(strict_types=1);

namespace App\Billing\Fx;

use App\Billing\Fx\Enums\FxRateOrigin;

/**
 * The outcome of pulling one FX rate source during a refresh: how many rates it persisted, or
 * the reason it failed. A failure is captured (not thrown) so one dead source never stops the
 * others — the refresher reports every result and the command decides the exit code.
 */
readonly class FxRefreshResult
{
    private function __construct(
        public FxRateOrigin $origin,
        public bool $ok,
        public int $count,
        public ?string $error,
    ) {}

    public static function ok(FxRateOrigin $origin, int $count): self
    {
        return new self($origin, true, $count, null);
    }

    public static function failed(FxRateOrigin $origin, string $error): self
    {
        return new self($origin, false, 0, $error);
    }
}
