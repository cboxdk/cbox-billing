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
    ) {}

    /** @param array<string, mixed> $claims */
    public static function fromClaims(array $claims): self
    {
        $name = (string) ($claims['name'] ?? $claims['preferred_username'] ?? $claims['email'] ?? 'User');

        return new self(
            sub: (string) ($claims['sub'] ?? ''),
            name: $name,
            email: (string) ($claims['email'] ?? ''),
            org: isset($claims['org']) ? (string) $claims['org'] : null,
            picture: isset($claims['picture']) ? (string) $claims['picture'] : null,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sub: (string) ($data['sub'] ?? ''),
            name: (string) ($data['name'] ?? 'User'),
            email: (string) ($data['email'] ?? ''),
            org: $data['org'] ?? null,
            picture: $data['picture'] ?? null,
        );
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

    /** Monogram for the organization chip. */
    public function orgInitials(): string
    {
        return Str::upper(Str::substr($this->org ?? 'Personal', 0, 2));
    }
}
