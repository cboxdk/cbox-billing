<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Environment;
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

    /** The console's active billing environment (plane) key — defaults to production. */
    private const ENVIRONMENT_KEY = 'console.environment';

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
        $this->session->forget([self::USER_KEY, self::ID_TOKEN_KEY, self::ENVIRONMENT_KEY]);
    }

    /** The console's active billing environment (plane) key — production unless switched. */
    public function activeEnvironmentKey(): string
    {
        $key = $this->session->get(self::ENVIRONMENT_KEY, Environment::PRODUCTION);

        return is_string($key) && $key !== '' ? $key : Environment::PRODUCTION;
    }

    /** Switch the console to a named environment (the persistent environment switcher). */
    public function setActiveEnvironment(string $key): void
    {
        $this->session->put(self::ENVIRONMENT_KEY, $key === '' ? Environment::PRODUCTION : $key);
    }

    /** Whether the console is currently viewing a NON-production (sandbox) plane. */
    public function inTestMode(): bool
    {
        return $this->activeEnvironmentKey() !== Environment::PRODUCTION;
    }

    /** BC bridge: flip the console between production and the default sandbox. */
    public function setTestMode(bool $enabled): void
    {
        $this->setActiveEnvironment($enabled ? Environment::SANDBOX : Environment::PRODUCTION);
    }
}
