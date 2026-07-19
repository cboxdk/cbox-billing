<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * The provider console's COARSE authorization boundary (SEC-1) — the single source of truth
 * for "is this session allowed to operate the console at all".
 *
 * The console is for the host's INTERNAL operators, who live in a dedicated operator
 * organization on Cbox ID. A valid Cbox ID session is NOT sufficient: Cbox ID is a live,
 * multi-tenant issuer that also holds customer/end-user accounts, so "completed OIDC" must
 * never imply "administers the provider". A session is admitted only when its identity's
 * organization is allowlisted ({@see \config/billing.php} → `console.operator_orgs`) or its
 * subject is individually allowlisted (`console.operator_subjects`, for break-glass).
 *
 * FAIL-CLOSED: when neither list is configured the boundary admits NO ONE ({@see allows()}
 * returns false for every session). {@see EnsureOperator} logs an actionable warning at that
 * point so the deny-by-default posture is never silent.
 *
 * This is orthogonal to and coarser than the per-permission RBAC ({@see EnforcePermission}):
 * this gate decides WHETHER a session may reach the console; RBAC refines access WITHIN it.
 */
class OperatorAccess
{
    public function __construct(private readonly Config $config) {}

    /**
     * The allowlisted operator organization ids.
     *
     * @return list<string>
     */
    public function operatorOrgs(): array
    {
        return $this->stringList('billing.console.operator_orgs');
    }

    /**
     * The individually allowlisted operator subjects (break-glass).
     *
     * @return list<string>
     */
    public function operatorSubjects(): array
    {
        return $this->stringList('billing.console.operator_subjects');
    }

    /**
     * Whether an allowlist has been configured at all. When false the console is fail-closed
     * (deny-all) — a deliberate deny-by-default posture, not an accident.
     */
    public function isConfigured(): bool
    {
        return $this->operatorOrgs() !== [] || $this->operatorSubjects() !== [];
    }

    /**
     * Whether the given principal may operate the console: its organization is allowlisted, or
     * its subject is individually allowlisted. A null principal, or an empty allowlist, is
     * denied (fail-closed).
     */
    public function allows(?AuthedUser $user): bool
    {
        if ($user === null || ! $this->isConfigured()) {
            return false;
        }

        $org = $user->org;
        if ($org !== null && $org !== '' && in_array($org, $this->operatorOrgs(), true)) {
            return true;
        }

        return $user->sub !== '' && in_array($user->sub, $this->operatorSubjects(), true);
    }

    /**
     * Read a config key as a list of non-empty strings, tolerating a raw string or a
     * mixed array (config caching and direct `config()->set()` both round-trip cleanly).
     *
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->config->get($key, []);

        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '' && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
