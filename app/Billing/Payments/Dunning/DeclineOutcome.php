<?php

declare(strict_types=1);

namespace App\Billing\Payments\Dunning;

use App\Billing\Payments\DeclineClassifier;

/**
 * The structured result of classifying a decline: the canonical, storable decline-code token
 * (e.g. `insufficient_funds`, `lost_card`) and the {@see DeclineCategory} the adaptive strategy
 * branches on. `code` is normalized — a real Stripe `decline_code`/`code` token when the reason
 * already is one, otherwise the token a best-effort phrase match resolved from a free-text
 * gateway message (see {@see DeclineClassifier}). It is never the raw,
 * unbounded gateway sentence — that would be useless as an analytics/grouping key.
 */
readonly class DeclineOutcome
{
    public function __construct(
        public string $code,
        public DeclineCategory $category,
    ) {}
}
