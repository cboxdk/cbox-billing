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
    public function __construct(
        public string $sub,
        public string $name,
        public string $email,
        public ?string $org,
        public ?string $picture,
        public ?string $orgName = null,
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
        );
    }

    /** Safely coerce a claim value to a string; non-scalar claims collapse to empty. */
    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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
