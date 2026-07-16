<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers an on-prem license to the customer's billing contact when it is issued or
 * reissued. Carries the copy-pasteable `CBOX_ID_LICENSE_KEY` value and install notes so the
 * operator can drop it straight into their self-hosted deployment. Queued from the licensing
 * service. `reissued` distinguishes a renewal (fresh key, extended window) from a first issue.
 */
class LicenseDeliveryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $licenseKey,
        public string $planLabel,
        public string $deploymentId,
        public string $expiresAtLabel,
        public bool $reissued = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reissued
            ? 'Your renewed Cbox license key'
            : 'Your Cbox license key');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.license-delivery');
    }
}
