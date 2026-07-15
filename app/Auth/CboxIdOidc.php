<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Contracts\IdentityProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cbox ID OIDC relying-party client. Discovers endpoints from the issuer, drives the
 * authorization-code + PKCE flow, and verifies id_tokens against the published JWKS
 * via the vetted firebase/php-jwt library (no hand-rolled JWT/crypto). Deny-by-default
 * throughout: unconfigured, unreachable, or unverifiable all raise rather than degrade.
 */
readonly class CboxIdOidc implements IdentityProvider
{
    /**
     * @param  array{issuer:?string, client_id:?string, client_secret:?string, redirect:?string, scopes:string}  $config
     */
    public function __construct(
        private array $config,
        private Cache $cache,
    ) {
        JWT::$leeway = 60; // tolerate small clock skew on exp/iat/nbf
    }

    public function isConfigured(): bool
    {
        return filled($this->config['issuer'])
            && filled($this->config['client_id'])
            && filled($this->config['redirect']);
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect'],
            'scope' => $this->config['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $this->endpoint('authorization_endpoint').'?'.http_build_query($params);
    }

    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config['redirect'],
            'client_id' => $this->config['client_id'],
            'code_verifier' => $codeVerifier,
        ];

        if (filled($this->config['client_secret'])) {
            $form['client_secret'] = $this->config['client_secret'];
        }

        $response = Http::asForm()->acceptJson()->post($this->endpoint('token_endpoint'), $form);

        if ($response->failed()) {
            throw new RuntimeException('Cbox ID token exchange failed: '.$response->body());
        }

        /** @var array{id_token?:string} $tokens */
        $tokens = $response->json();

        if (! isset($tokens['id_token']) || ! is_string($tokens['id_token'])) {
            throw new RuntimeException('Cbox ID token response is missing an id_token.');
        }

        return $tokens;
    }

    public function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        $discovery = $this->discovery();
        $algs = $discovery['id_token_signing_alg_values_supported'] ?? ['RS256'];
        $keys = JWK::parseKeySet($this->jwks(), is_array($algs) ? (string) ($algs[0] ?? 'RS256') : 'RS256');

        // Verifies signature (kid-matched), exp, iat and nbf; throws otherwise.
        $claims = (array) JWT::decode($idToken, $keys);

        $issuer = rtrim((string) $this->config['issuer'], '/');
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $issuer) {
            throw new RuntimeException('id_token issuer mismatch.');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($this->config['client_id'], $audiences, true)) {
            throw new RuntimeException('id_token audience mismatch.');
        }

        if (isset($claims['azp']) && $claims['azp'] !== $this->config['client_id']) {
            throw new RuntimeException('id_token authorized-party mismatch.');
        }

        if (! isset($claims['nonce']) || ! hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new RuntimeException('id_token nonce mismatch.');
        }

        return $claims;
    }

    public function fetchUserInfo(string $accessToken): array
    {
        $endpoint = $this->discovery()['userinfo_endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            return [];
        }

        $response = Http::acceptJson()->withToken($accessToken)->get($endpoint);

        return $response->successful() ? (array) $response->json() : [];
    }

    public function endSessionUrl(?string $idTokenHint, string $postLogoutRedirectUri): ?string
    {
        $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $params = array_filter([
            'id_token_hint' => $idTokenHint,
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
            'client_id' => $this->config['client_id'],
        ]);

        return $endpoint.'?'.http_build_query($params);
    }

    /**
     * The cached OIDC discovery document.
     *
     * @return array<string, mixed>
     */
    private function discovery(): array
    {
        return $this->cache->remember('cbox_id.discovery', now()->addMinutes(10), function (): array {
            $url = rtrim((string) $this->config['issuer'], '/').'/.well-known/openid-configuration';
            $response = Http::acceptJson()->get($url);

            if ($response->failed()) {
                throw new RuntimeException("Cbox ID discovery unreachable at {$url} (HTTP {$response->status()}).");
            }

            return $response->json();
        });
    }

    /**
     * The cached JWK Set used to verify id_token signatures.
     *
     * @return array<string, mixed>
     */
    private function jwks(): array
    {
        $uri = $this->endpoint('jwks_uri');

        return $this->cache->remember('cbox_id.jwks', now()->addMinutes(10), function () use ($uri): array {
            $response = Http::acceptJson()->get($uri);

            if ($response->failed()) {
                throw new RuntimeException("Cbox ID JWKS unreachable at {$uri}.");
            }

            return $response->json();
        });
    }

    private function endpoint(string $key): string
    {
        $value = $this->discovery()[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Cbox ID discovery is missing '{$key}'.");
        }

        return $value;
    }
}
