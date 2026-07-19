<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Contracts;

/**
 * Renders a DB- or file-authored template string against a variable bag WITHOUT ever
 * evaluating Blade or PHP. The one seam every stored-template render goes through, so the
 * sandbox guarantee (escaped values, no code execution) lives in exactly one place and is
 * swappable/fakeable.
 */
interface RendersTemplates
{
    /**
     * Render `$template` (restricted mustache syntax) against `$variables`. Interpolated
     * values are HTML-escaped; unknown variables render empty. `$escape=false` yields plain
     * text (used for the subject line and the plain-text alternative), still never executing
     * code.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(string $template, array $variables, bool $escape = true): string;
}
