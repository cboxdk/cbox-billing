<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Contracts;

use App\Billing\Notifications\MailEventType;
use App\Billing\Notifications\Rendering\ResolvedTemplate;

/**
 * Resolves the effective template for an event, walking the layered chain
 * (seller+locale → seller+fallback → account+locale → account+fallback → shipped default) so
 * a render never dead-ends. The one place resolution policy lives, behind a contract so the
 * console and the notifier resolve identically.
 */
interface ResolvesMailTemplates
{
    public function resolve(MailEventType $event, string $locale, ?string $sellerEntityId): ResolvedTemplate;
}
