<?php

declare(strict_types=1);

namespace App\Billing\Environments;

use App\Billing\Payments\WebhookPlaneResolver;
use App\Billing\Seller\SellerCatalog;
use App\Models\Environment;

/**
 * Derives the PLANE-DISTINCT legal-document prefix a seller numbers with inside a given plane.
 *
 * WHY. A legal document number is `<PREFIX>-<YEAR>-<0000N>` (invoices) / `<PREFIX>-CN-<YEAR>-<0000N>`
 * (credit notes), and the counter behind `N` is per SELLER. Two planes that hold the same prefix
 * therefore mint byte-identical numbers — production and a sandbox both issuing `CBOX-DK-2026-00001` —
 * which is what made an unscoped, reference-only settlement payload ambiguous across planes
 * ({@see WebhookPlaneResolver}). Two paths produced that collision:
 *
 *   1. {@see EnvironmentCloner} copies the seller register into the clone. The clone's seller gets a
 *      plane-namespaced PRIMARY key (`{plane}__{sourceId}`) but used to inherit the prefix verbatim.
 *   2. A plane with no authored seller rows falls back to the `billing.seller` CONFIG
 *      ({@see SellerCatalog}) — the same id AND the same prefix in every plane.
 *
 * THE SCHEME. Production is authoritative and never rewritten: its prefix is returned untouched, so
 * the legal series a deployment already issues is stable forever. Every OTHER plane appends the
 * plane's own key, upper-cased, as a marker:
 *
 *     CBOX-DK  +  plane `ci-clone`   →  CBOX-DK-CI-CLONE  →  CBOX-DK-CI-CLONE-2026-00001
 *
 * The environment key is unique by definition, so the derived prefix is unique per plane, readable
 * (an operator can tell at a glance which plane a document came from), deterministic and stable —
 * re-cloning or reseeding the same plane derives the same prefix. It is also IDEMPOTENT: a prefix
 * that already carries the plane's marker is returned unchanged, so a backfill or a repeated reseed
 * never stacks markers.
 *
 * WIDTH. `seller_entities.invoice_prefix` is authored under `max:40` (and matched by
 * `/^[A-Za-z0-9._-]+$/`, which an upper-cased environment key always satisfies). When the plane key
 * is too long to fit, the marker degrades to a short deterministic digest of the key rather than a
 * truncation of it — truncating the key itself could make two long plane keys share a marker.
 */
readonly class PlaneDocumentPrefix
{
    /** The authored width of `seller_entities.invoice_prefix` (`max:40`). */
    public const MAX = 40;

    /** Width of the digest marker used when the plane key does not fit. */
    private const DIGEST = 6;

    /**
     * The prefix `$prefix` becomes inside the plane `$environmentKey` — unchanged in production,
     * plane-marked everywhere else.
     */
    public static function for(string $prefix, string $environmentKey): string
    {
        if ($prefix === '' || $environmentKey === '' || $environmentKey === Environment::PRODUCTION) {
            return $prefix;
        }

        $marker = self::marker($prefix, $environmentKey);

        if (str_ends_with($prefix, '-'.$marker)) {
            return $prefix;
        }

        return self::stem($prefix, $marker).'-'.$marker;
    }

    /**
     * Move a prefix from one plane to another: strip `$fromKey`'s marker (if it carries one) and
     * derive `$toKey`'s. This is how a promoted seller gets a prefix that belongs to the plane it
     * lands in instead of dragging the source sandbox's marker into it.
     */
    public static function rebase(string $prefix, string $fromKey, string $toKey): string
    {
        return self::for(self::strip($prefix, $fromKey), $toKey);
    }

    /** `$prefix` without `$environmentKey`'s marker, if it ends in one. */
    private static function strip(string $prefix, string $environmentKey): string
    {
        if ($environmentKey === '' || $environmentKey === Environment::PRODUCTION) {
            return $prefix;
        }

        foreach ([strtoupper($environmentKey), strtoupper(substr(hash('sha256', $environmentKey), 0, self::DIGEST))] as $marker) {
            $suffix = '-'.$marker;

            if (str_ends_with($prefix, $suffix)) {
                return substr($prefix, 0, -strlen($suffix));
            }
        }

        return $prefix;
    }

    /**
     * The plane marker: the upper-cased environment key when the whole prefix fits inside
     * {@see MAX}, else a short deterministic digest of that key (collision-resistant where a
     * truncated key would not be).
     */
    private static function marker(string $prefix, string $environmentKey): string
    {
        $key = strtoupper($environmentKey);

        if (strlen($prefix) + 1 + strlen($key) <= self::MAX) {
            return $key;
        }

        return strtoupper(substr(hash('sha256', $environmentKey), 0, self::DIGEST));
    }

    /** The source prefix trimmed to leave room for `-<marker>` inside {@see MAX}. */
    private static function stem(string $prefix, string $marker): string
    {
        $stem = substr($prefix, 0, max(1, self::MAX - strlen($marker) - 1));
        $trimmed = rtrim($stem, '-._');

        return $trimmed === '' ? $stem : $trimmed;
    }
}
