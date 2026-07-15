<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Contracts\Session\Session;

/**
 * The current authenticated user, held in the session (the principal lives in Cbox
 * ID, so there is no local users table). Wraps session reads/writes behind one seam
 * so controllers, middleware and views never poke session keys directly.
 */
class CurrentUser
{
    private const USER_KEY = 'auth.user';

    private const ID_TOKEN_KEY = 'auth.id_token';

    public function __construct(private readonly Session $session) {}

    public function check(): bool
    {
        return $this->session->has(self::USER_KEY);
    }

    public function user(): ?AuthedUser
    {
        $data = $this->session->get(self::USER_KEY);

        if (! is_array($data)) {
            return null;
        }

        $claims = [];
        foreach ($data as $key => $value) {
            $claims[(string) $key] = $value;
        }

        return AuthedUser::fromArray($claims);
    }

    public function idToken(): ?string
    {
        $token = $this->session->get(self::ID_TOKEN_KEY);

        return is_string($token) ? $token : null;
    }

    public function login(AuthedUser $user, ?string $idToken = null): void
    {
        $this->session->put(self::USER_KEY, $user->toArray());

        if ($idToken !== null) {
            $this->session->put(self::ID_TOKEN_KEY, $idToken);
        }
    }

    public function logout(): void
    {
        $this->session->forget([self::USER_KEY, self::ID_TOKEN_KEY]);
    }
}
