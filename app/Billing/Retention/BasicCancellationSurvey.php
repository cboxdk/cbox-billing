<?php

declare(strict_types=1);

namespace App\Billing\Retention;

use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\NullCancellationSurvey;
use Cbox\Billing\Retention\ValueObjects\CancellationReason;

/**
 * The app's own default {@see CancellationSurvey}: a small, built-in list of churn reasons
 * offered to every subscriber who cancels. It binds over the engine's inert
 * {@see NullCancellationSurvey} so the deployable app always shows a
 * basic survey; when the private `cbox-billing-retention` plugin is composed in it rebinds
 * this contract to the rich, per-merchant survey — with zero app edits.
 */
readonly class BasicCancellationSurvey implements CancellationSurvey
{
    public function reasonsFor(string $account, string $subscriptionId): array
    {
        return [
            new CancellationReason('too_expensive', 'Too expensive'),
            new CancellationReason('missing_features', 'Missing features'),
            new CancellationReason('switching_provider', 'Switching provider'),
            new CancellationReason('no_longer_needed', 'No longer needed'),
            new CancellationReason('technical_issues', 'Technical issues'),
            new CancellationReason('other', 'Other', requiresComment: true),
        ];
    }
}
