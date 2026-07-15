<?php

declare(strict_types=1);

namespace App\Auth\Contracts;

/**
 * The identity provider cbox-billing authenticates against (Cbox ID), spoken as a
 * standard OIDC relying party. All endpoints are DISCOVERED from the issuer's
 * `.well-known/openid-configuration` — never hard-coded. Every method fails closed:
 * a discovery/JWKS/verification error throws rather than returning a usable result.
 */
interface IdentityProvider
{
    /** Whether an issuer + client are configured (false → local demo mode). */
    public function isConfigured(): bool;

    /** The authorization-endpoint URL to redirect the user to (code + PKCE). */
    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string;

    /**
     * Exchange an authorization code for tokens at the token endpoint.
     *
     * @return array{id_token:string, access_token?:string, refresh_token?:string, expires_in?:int}
     */
    public function exchangeCode(string $code, string $codeVerifier): array;

    /**
     * Verify an id_token: signature against the issuer's JWKS, then iss / aud / exp
     * / nonce. Returns the validated claim set, or throws on any failure.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken, string $expectedNonce): array;

    /**
     * Fetch the UserInfo claims (name, email, …) for an access token. Returns an
     * empty array if the provider advertises no endpoint or the call fails.
     *
     * @return array<string, mixed>
     */
    public function fetchUserInfo(string $accessToken): array;

    /** RP-initiated logout URL, or null if the provider advertises none. */
    public function endSessionUrl(?string $idTokenHint, string $postLogoutRedirectUri): ?string;
}
