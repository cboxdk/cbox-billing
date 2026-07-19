<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Contracts;

use App\Billing\Notifications\MailEventType;
use App\Billing\Notifications\Rendering\RenderedMail;

/**
 * The render pipeline: resolve the template (chain) → apply the seller branding → localize →
 * safe-render → wrap in the branded layout → derive plain text. The single seam both the
 * lifecycle mail and the console live-preview / test-send go through, so an operator previews
 * exactly what a customer receives.
 */
interface ComposesTransactionalMail
{
    /**
     * @param  array<string, mixed>  $variables  The event's variable bag (already locale-formatted).
     */
    public function compose(MailEventType $event, array $variables, ?string $sellerEntityId, string $locale): RenderedMail;

    /**
     * Compose from an explicit subject + body rather than the resolved template — the live
     * preview of an UNSAVED draft in the console editor. Same branding/layout/plain-text
     * pipeline, so the preview of a draft is exactly what saving-then-sending would produce.
     *
     * @param  array<string, mixed>  $variables
     */
    public function composeDraft(MailEventType $event, string $subject, string $body, array $variables, ?string $sellerEntityId, string $locale): RenderedMail;
}
