<?php

declare(strict_types=1);

namespace App\Platform;

use App\Auth\CurrentUser;
use Cbox\Console\Kit\Contracts\CurrentContext;

/**
 * Adapts the console-kit {@see CurrentContext} onto this app's {@see CurrentUser}, so a
 * plugin (a private commercial console module) can resolve the current organization and
 * user without depending on this app's auth internals or the OIDC claim shape.
 *
 * The principal lives in Cbox ID; there is no local roles table and the provider console
 * is a single operator surface, so any authenticated session administers it — hence
 * {@see isAdmin()} tracks {@see CurrentUser::check()}. This is a presence adapter only; it
 * is DISTINCT from the entitlement/upgrade soft-lock, which stays an app concern.
 */
class ConsoleCurrentContext implements CurrentContext
{
    public function __construct(private readonly CurrentUser $me) {}

    public function organizationId(): ?string
    {
        $org = $this->me->user()?->org;

        return $org !== null && $org !== '' ? $org : null;
    }

    public function userId(): ?string
    {
        $sub = $this->me->user()?->sub;

        return $sub !== null && $sub !== '' ? $sub : null;
    }

    public function isAdmin(): bool
    {
        return $this->me->check();
    }
}
