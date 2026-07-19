<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Rendering;

/**
 * A fully-composed transactional email — the branded, localized HTML document, its
 * auto-derived plain-text alternative, the resolved subject, and the from/reply-to identity —
 * ready to hand to the mailer or to preview verbatim. Everything the console previews is a
 * RenderedMail, so the preview is exactly what ships.
 */
readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
        public string $fromName,
        public string $fromEmail,
        public ?string $replyTo,
        public string $locale,
        public TemplateSource $source,
    ) {}
}
