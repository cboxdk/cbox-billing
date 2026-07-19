<?php

declare(strict_types=1);

namespace App\Mail;

use App\Billing\Notifications\MailEventType;

/**
 * Delivers an on-prem license to the customer's billing contact when it is issued or
 * reissued. Carries the copy-pasteable `CBOX_ID_LICENSE_KEY` value and install notes.
 * `reissued` distinguishes a renewal from a first issue. Rendered through the branded,
 * localized template system (see {@see TransactionalMailable}).
 */
class LicenseDeliveryMail extends TransactionalMailable
{
    public function __construct(
        public string $organizationName,
        public string $licenseKey,
        public string $planLabel,
        public string $deploymentId,
        public string $expiresAtLabel,
        public bool $reissued = false,
    ) {}

    public function eventType(): MailEventType
    {
        return MailEventType::LicenseDelivered;
    }

    public function variables(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'license_key' => $this->licenseKey,
            'plan_label' => $this->planLabel,
            'deployment_id' => $this->deploymentId,
            'expires_at_label' => $this->expiresAtLabel,
            'reissued' => $this->reissued,
        ];
    }
}
