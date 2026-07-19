<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Rendering;

use App\Billing\Notifications\MailEventType;

/**
 * A template resolved from the chain, ready to render: the raw (unrendered) subject + body,
 * the locale that actually served it (which may be the fallback), and the {@see TemplateSource}
 * layer it came from. The composer renders these; the console reports the source.
 */
readonly class ResolvedTemplate
{
    public function __construct(
        public MailEventType $event,
        public string $subject,
        public string $body,
        public string $locale,
        public TemplateSource $source,
    ) {}
}
