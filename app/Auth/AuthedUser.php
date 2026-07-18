<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Str;

/**
 * The authenticated principal, projected from validated OIDC id_token claims. The
 * user lives in Cbox ID — this is a read model, not a local account. `sub` is the
 * canonical cross-product identifier; `org` is the active organization scope.
 */
readonly class AuthedUser
{
    /**
     * @param  list<string>  $roles  the role keys Cbox ID assigned (empty until id emits them)
     * @param  list<string>  $permissions  the resolved `feature:action` slugs (empty until id emits them)
     * @param  ?string  $environment  the Cbox ID environment key/ULID the session is in (null until id emits it)
     * @param  ?string  $environmentName  the human name of that environment (null until id emits it)
     */
    public function __construct(
        public string $sub,
        public string $name,
        public string $email,
        public ?string $org,
        public ?string $picture,
        public ?string $orgName = null,
        public array $roles = [],
        public array $permissions = [],
        public ?string $environment = null,
        public ?string $environmentName = null,
    ) {}

    /** @param array<string, mixed> $claims */
    public static function fromClaims(array $claims): self
    {
        $name = self::str($claims['name'] ?? $claims['preferred_username'] ?? $claims['email'] ?? 'User');

        return new self(
            sub: self::str($claims['sub'] ?? ''),
            name: $name,
            email: self::str($claims['email'] ?? ''),
            org: isset($claims['org']) ? self::str($claims['org']) : null,
            picture: isset($claims['picture']) ? self::str($claims['picture']) : null,
            orgName: isset($claims['org_name']) ? self::str($claims['org_name']) : null,
            roles: self::strList($claims['roles'] ?? null),
            permissions: self::strList($claims['permissions'] ?? null),
            environment: self::nullableStr($claims['environment'] ?? null),
            environmentName: self::nullableStr($claims['environment_name'] ?? null),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sub: self::str($data['sub'] ?? ''),
            name: self::str($data['name'] ?? 'User'),
            email: self::str($data['email'] ?? ''),
            org: isset($data['org']) ? self::str($data['org']) : null,
            picture: isset($data['picture']) ? self::str($data['picture']) : null,
            orgName: isset($data['org_name']) ? self::str($data['org_name']) : null,
            roles: self::strList($data['roles'] ?? null),
            permissions: self::strList($data['permissions'] ?? null),
            environment: self::nullableStr($data['environment'] ?? null),
            environmentName: self::nullableStr($data['environment_name'] ?? null),
        );
    }

    /** Safely coerce a claim value to a string; non-scalar claims collapse to empty. */
    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** A claim value as a non-empty string, or null when absent/blank/non-scalar. */
    private static function nullableStr(mixed $value): ?string
    {
        $string = self::str($value);

        return $string !== '' ? $string : null;
    }

    /**
     * Normalize a claim value to a de-duplicated list of non-empty strings. Accepts a JSON
     * array (`["a","b"]`) or a single space/comma-delimited string; anything else is empty.
     *
     * @return list<string>
     */
    private static function strList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', trim($value)) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $slug = self::str($item);
            if ($slug !== '' && ! in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /** Whether the principal carries a given `feature:action` permission slug. */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * The human label for the active environment: the `environment_name` claim, falling
     * back to the opaque `environment` key, then null when the session carries neither.
     * A caller wanting a guaranteed label supplies the configured default fallback.
     */
    public function environmentLabel(): ?string
    {
        if ($this->environmentName !== null && $this->environmentName !== '') {
            return $this->environmentName;
        }

        return $this->environment;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'name' => $this->name,
            'email' => $this->email,
            'org' => $this->org,
            'picture' => $this->picture,
            'org_name' => $this->orgName,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'environment' => $this->environment,
            'environment_name' => $this->environmentName,
        ];
    }

    /** Two-letter monogram for the avatar chip. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $parts = array_values(array_filter($parts));

        if (count($parts) >= 2) {
            return Str::upper(Str::substr($parts[0], 0, 1).Str::substr($parts[count($parts) - 1], 0, 1));
        }

        $source = $this->name !== '' ? $this->name : $this->email;

        return Str::upper(Str::substr($source, 0, 2));
    }

    /**
     * The human label for the active organization: the `org_name` claim Cbox ID emits,
     * falling back to the opaque org id, then to "Personal" when unscoped. Callers should
     * render this, never the raw `org` ulid.
     */
    public function orgLabel(): string
    {
        if ($this->orgName !== null && $this->orgName !== '') {
            return $this->orgName;
        }

        return ($this->org !== null && $this->org !== '') ? $this->org : 'Personal';
    }

    /** Monogram for the organization chip, derived from the human org label. */
    public function orgInitials(): string
    {
        $label = $this->orgLabel();
        $parts = array_values(array_filter(preg_split('/\s+/', trim($label)) ?: []));

        if (count($parts) >= 2) {
            return Str::upper(Str::substr($parts[0], 0, 1).Str::substr($parts[count($parts) - 1], 0, 1));
        }

        return Str::upper(Str::substr($label, 0, 2));
    }
}
