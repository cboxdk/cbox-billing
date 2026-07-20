<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * The metered-usage alert (optional courtesy mail): sent when an org's usage of a metered
 * dimension crosses a configured threshold of its included allowance (e.g. 80% / 100%), so a
 * customer is not surprised by overage. Rendered through the branded, localized template system
 * (see {@see TransactionalMailable}); suppressed when the account opts out of the optional
 * usage-alert notification.
 */
class UsageAlertMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $meterName,
        public int $thresholdPercent,
        public int $usagePercent,
        public string $usedFormatted,
        public string $allowanceFormatted,
        public string $periodEndLabel,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::UsageAlert;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'meter_name' => $this->meterName,
            'threshold_percent' => (string) $this->thresholdPercent,
            'usage_percent' => (string) $this->usagePercent,
            'used_formatted' => $this->usedFormatted,
            'allowance_formatted' => $this->allowanceFormatted,
            'period_end_label' => $this->periodEndLabel,
        ];
    }
}
