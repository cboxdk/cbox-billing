<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Mode\EnvironmentScope;
use App\Models\Quote;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Generates the next human quote number (`Q-00001`). The prefix is configurable
 * (`billing.quotes.number_prefix`); the sequence is the max existing id + 1, zero-padded. The
 * console is a single operator surface, so a monotonic max-id sequence is sufficient and readable;
 * the `number` column's UNIQUE constraint is the backstop.
 *
 * The sequence spans BOTH planes: `number` is globally unique (its constraint is not
 * plane-scoped), and it tracks the global `id`, so the max is read WITHOUT the {@see EnvironmentScope}.
 * Were it read per-plane, a test and a live quote could derive the same number and collide on the
 * global unique index.
 */
readonly class QuoteNumberGenerator
{
    public function __construct(private Config $config) {}

    public function next(): string
    {
        $prefix = $this->config->get('billing.quotes.number_prefix');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'Q-';

        $max = Quote::query()->withoutGlobalScope(EnvironmentScope::class)->max('id');
        $sequence = (is_numeric($max) ? (int) $max : 0) + 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
