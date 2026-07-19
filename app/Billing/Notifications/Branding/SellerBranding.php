<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Branding;

/**
 * The resolved, ready-to-render branding for one transactional email: the selling entity's
 * customer-facing identity with every app-level default already filled in. The email layout
 * reads only this — it never touches a model or config — so what the console previews is
 * exactly what ships.
 */
readonly class SellerBranding
{
    public function __construct(
        public ?string $sellerEntityId,
        public string $productName,
        public string $brandColor,
        public ?string $logoUrl,
        public string $fromName,
        public string $fromEmail,
        public ?string $replyTo,
        public string $legalName,
        public string $registrationNumber,
        public ?string $footerAddress,
        public ?string $supportUrl,
        public ?string $supportEmail,
    ) {}

    /**
     * The legal footer line — the entity of record + its registration number. Emails must
     * carry the issuing legal identity, so this is never empty when a legal name is known.
     */
    public function legalLine(): string
    {
        if ($this->registrationNumber !== '') {
            return $this->legalName.' · '.$this->registrationNumber;
        }

        return $this->legalName;
    }
}
