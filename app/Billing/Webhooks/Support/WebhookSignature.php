<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Support;

use App\Billing\Payments\ManualWebhookVerifier;

/**
 * The outbound signing scheme — symmetric, by construction, with the app's own INBOUND
 * verifier ({@see ManualWebhookVerifier}): the same vetted primitive
 * (`hash_hmac('sha256', …)`) and the same constant-time comparison (`hash_equals`). No bespoke
 * crypto is hand-rolled here.
 *
 * The signature binds a moment to the body so a receiver can reject a replay outside its
 * tolerance window: the MAC is computed over `"{timestamp}.{body}"`, and the wire carries the
 * timestamp both explicitly (`X-Cbox-Timestamp`) and inside the versioned signature
 * (`X-Cbox-Signature: t={timestamp},v1={hex}`). A receiver recomputes with the shared secret and
 * compares in constant time; a tampered body or a wrong secret fails.
 */
class WebhookSignature
{
    public const TIMESTAMP_HEADER = 'X-Cbox-Timestamp';

    public const SIGNATURE_HEADER = 'X-Cbox-Signature';

    /**
     * Sign a raw JSON body at a given unix timestamp. Returns the headers to send.
     *
     * @return array{'X-Cbox-Timestamp': string, 'X-Cbox-Signature': string}
     */
    public static function headers(string $body, string $secret, int $timestamp): array
    {
        $signature = self::compute($body, $secret, $timestamp);

        return [
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SIGNATURE_HEADER => 't='.$timestamp.',v1='.$signature,
        ];
    }

    /** The raw hex MAC over `"{timestamp}.{body}"`. */
    public static function compute(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Verify a delivery the way a receiver would: recompute over `"{t}.{body}"` from the parsed
     * `t=`/`v1=` header and constant-time compare. When `$toleranceSeconds` is given, a timestamp
     * outside `[now-tolerance, now+tolerance]` is rejected as a replay. Symmetric with the inbound
     * verifier's `hash_equals` check.
     */
    public static function verify(
        string $body,
        string $secret,
        string $signatureHeader,
        ?int $toleranceSeconds = null,
        ?int $now = null,
    ): bool {
        $parsed = self::parse($signatureHeader);

        if ($parsed === null) {
            return false;
        }

        [$timestamp, $provided] = $parsed;

        if ($toleranceSeconds !== null) {
            $now ??= time();

            if (abs($now - $timestamp) > $toleranceSeconds) {
                return false;
            }
        }

        $expected = self::compute($body, $secret, $timestamp);

        return hash_equals($expected, $provided);
    }

    /**
     * Parse a `t={int},v1={hex}` header into `[timestamp, signature]`, or null if malformed.
     *
     * @return array{0: int, 1: string}|null
     */
    public static function parse(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null || $signature === '') {
            return null;
        }

        return [$timestamp, $signature];
    }
}
