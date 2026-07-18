<?php

declare(strict_types=1);

namespace App\Platform;

use App\Auth\AuthedUser;
use App\Auth\CurrentUser;
use App\Models\Organization;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the ACTIVE Cbox ID environment for the current console session and keeps each
 * org's recorded home environment in step — the app-side seam for Cbox ID's
 * environment-scoping.
 *
 * DEFENSIVE + BC. Cbox ID does not yet emit an `environment` claim, so resolution falls
 * back to the single configured default (`cbox-id-client.environment_default`) — mirroring
 * Cbox ID's own host→default fallback, so a host-less single-environment deploy Just Works.
 * When a login DOES carry the claim, {@see stamp()} records it on the org the first time it
 * is seen; a later mismatch is logged and the recorded value kept, so a tenant is never
 * silently moved between planes.
 */
class EnvironmentContext
{
    public function __construct(
        private readonly CurrentUser $current,
        private readonly Config $config,
    ) {}

    /** The configured default environment key (the single-plane fallback). */
    public function default(): string
    {
        $value = $this->config->get('cbox-id-client.environment_default', 'default');

        return is_string($value) && $value !== '' ? $value : 'default';
    }

    /** The active environment key for the signed-in session, or the default when unscoped. */
    public function current(): string
    {
        return $this->forUser($this->current->user());
    }

    /** The active environment key for a given principal (claim → configured default). */
    public function forUser(?AuthedUser $user): string
    {
        if ($user !== null && $user->environment !== null && $user->environment !== '') {
            return $user->environment;
        }

        return $this->default();
    }

    /** The human label for the active environment (name claim → key → default). */
    public function label(): string
    {
        $user = $this->current->user();
        $label = $user?->environmentLabel();

        return $label ?? $this->current();
    }

    /**
     * Stamp/verify the login's environment on its org, on first sight. With no recorded
     * value the org is stamped to the claimed environment (or the default when the login
     * carries no claim); a claim that disagrees with an already-recorded value is logged and
     * the recorded value kept — billing never silently moves a tenant between planes.
     */
    public function stamp(AuthedUser $user): void
    {
        if ($user->org === null || $user->org === '') {
            return;
        }

        $organization = Organization::query()->find($user->org);

        if (! $organization instanceof Organization) {
            return;
        }

        $claim = $user->environment !== null && $user->environment !== '' ? $user->environment : null;
        $recorded = $organization->environment_key;

        if ($recorded === null || $recorded === '') {
            $organization->forceFill(['environment_key' => $claim ?? $this->default()])->save();

            return;
        }

        if ($claim !== null && $claim !== $recorded) {
            Log::warning('Cbox ID environment claim does not match the recorded org environment; keeping the recorded one.', [
                'organization' => $organization->getKey(),
                'recorded' => $recorded,
                'claimed' => $claim,
            ]);
        }
    }
}
