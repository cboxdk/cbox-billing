<?php

declare(strict_types=1);

namespace App\Billing\Support;

/**
 * Derives a short monogram (the avatar initials the app-shell renders) from an
 * organization or person name — presentation only, never identity.
 */
class Initials
{
    public static function of(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));

        if ($words === []) {
            return '··';
        }

        if (count($words) === 1) {
            return strtoupper(mb_substr($words[0], 0, 2));
        }

        return strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[count($words) - 1], 0, 1));
    }
}
