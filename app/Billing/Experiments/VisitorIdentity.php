<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The anonymous, privacy-preserving visitor identity used for sticky A/B assignment.
 *
 * It is a random 32-hex-char token in a first-party cookie — NOT a customer id, an email, an IP or
 * any fingerprint. It exists only to make a visitor's variant assignment stable across page views
 * (so a returning visitor sees a consistent price) and to dedupe impressions/conversions. It
 * carries no personal data, is never joined to a customer, and an operator can drop it entirely
 * without affecting billing.
 *
 * Cookie policy: `SameSite=Lax`, `HttpOnly` (the server is the only reader — no client JS needs
 * it), one-year lifetime. A cross-site EMBED (an iframe on a third-party marketing site) will not
 * receive a Lax first-party cookie, so an embedded visitor is treated as a fresh anonymous visitor
 * each load — a deliberate privacy trade-off documented for operators who need cross-site stitching.
 */
readonly class VisitorIdentity
{
    public const string COOKIE = 'cbox_vid';

    private const int LIFETIME_MINUTES = 525600; // one year

    /** The visitor id carried by the request, or null when the cookie is absent/invalid. */
    public function fromRequest(Request $request): ?string
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        // A well-formed token only (32 lowercase hex chars) — reject anything tampered/oversized.
        return preg_match('/^[a-f0-9]{32}$/', $value) === 1 ? $value : null;
    }

    /** A fresh random anonymous visitor id. */
    public function mint(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The visitor id from the request, minting a new one when absent. */
    public function resolve(Request $request): string
    {
        return $this->fromRequest($request) ?? $this->mint();
    }

    /** The cookie that persists a (possibly freshly minted) visitor id on the response. */
    public function cookie(string $visitorId): Cookie
    {
        return new Cookie(
            name: self::COOKIE,
            value: $visitorId,
            expire: now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp(),
            path: '/',
            domain: null,
            // `secure = null` means "use the request's scheme" — Secure over HTTPS, sendable in
            // plain-HTTP local dev; HttpOnly since only the server reads it; Lax for first-party.
            secure: null,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
