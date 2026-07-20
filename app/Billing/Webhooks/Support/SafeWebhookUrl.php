<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Support;

use App\Billing\Webhooks\Exceptions\UnsafeWebhookUrl;
use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\Exceptions\BlockedUrl;

/**
 * SSRF gate for outbound webhook endpoint URLs. The actual guarding — scheme/credential checks,
 * dual-stack DNS resolution, private/reserved/loopback/link-local/cloud-metadata blocking, and
 * connection pinning — lives in the shared, independently-tested `cboxdk/laravel-ssrf` package.
 * This thin adapter keeps the app's own on/off toggle (`billing.webhooks.verify_url`) and
 * narrows the accepted schemes to `http`/`https` with no embedded credentials.
 *
 * Outbound delivery is a new SSRF sink this app did not previously have (all prior webhook code
 * is inbound). {@see assert()} guards the URL at registration; {@see pinnedOptions()} re-validates
 * AND pins the resolved IPs immediately before the connect, so a DNS rebind between the
 * registration check and the delivery cannot redirect the request to an internal address
 * (TOCTOU-closed). Redirects are refused by the pinned options too.
 */
class SafeWebhookUrl
{
    /** The only schemes an outbound webhook may target. */
    private const SCHEMES = ['http', 'https'];

    public static function isSafe(string $url): bool
    {
        try {
            self::assert($url);

            return true;
        } catch (UnsafeWebhookUrl) {
            return false;
        }
    }

    /**
     * @throws UnsafeWebhookUrl
     */
    public static function assert(string $url): void
    {
        if (! self::enforced()) {
            return;
        }

        try {
            app(UrlGuard::class)->assertSafe($url, self::SCHEMES, false);
        } catch (BlockedUrl $e) {
            throw UnsafeWebhookUrl::make($e->getMessage());
        }
    }

    /**
     * Validate the URL and return Guzzle options that PIN the connection to the exact IPs just
     * resolved and refuse redirects — spread these into the HTTP client immediately before the
     * POST so the check and the connect share one DNS resolution (TOCTOU-closed). Returns an empty
     * array when enforcement is disabled.
     *
     * @return array<string, mixed>
     *
     * @throws UnsafeWebhookUrl
     */
    public static function pinnedOptions(string $url): array
    {
        if (! self::enforced()) {
            return [];
        }

        try {
            return app(UrlGuard::class)->pinnedOptions($url, self::SCHEMES, false);
        } catch (BlockedUrl $e) {
            throw UnsafeWebhookUrl::make($e->getMessage());
        }
    }

    private static function enforced(): bool
    {
        return config('billing.webhooks.verify_url', true) !== false;
    }
}
