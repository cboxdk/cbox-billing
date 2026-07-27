<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every `cbx-*` class used in a Blade template must have a rule behind it.
 *
 * A class name with no rule fails SILENTLY — the element just renders unstyled, and in a
 * console that mostly looks fine, so nothing draws attention to it. The review found four:
 *
 *   - `.cbx-pill--danger` (the design system's variant is `--destructive`). On /console/nexus
 *     that made "3 triggered" — meaning US economic-nexus thresholds are crossed and the seller
 *     has an unregistered tax liability — render as the same neutral chip as any inert label.
 *     The severity signal was dropped entirely, on the page whose only job is severity.
 *   - `.cbx-pill--neutral`, which happened to coincide with the default and so was harmless.
 *   - `.cbx-card` on the console topbar's clone popover: no background, no border, no shadow,
 *     so the form rendered transparent over the table beneath it — on every console page.
 *   - `.cbx-label`, which left section headings looking like body text.
 *
 * A rendering test would not have caught any of these: the markup was valid and the page
 * returned 200. Only comparing the two sources does.
 */
class DesignSystemClassesTest extends TestCase
{
    /**
     * Class names that carry NO rule and are not meant to.
     *
     * These elements are styled by inline `style=` attributes, so nothing renders wrong — the
     * class is a leftover semantic hook. That is cosmetic debt, not the defect this test exists
     * to catch, which is an element that DEPENDS on a class for its appearance and silently
     * loses it (`.cbx-pill--danger` dropping the severity colour on the nexus page).
     *
     * Each entry is a deliberate acceptance, not a blanket allowance: a NEW undefined class
     * still fails. Tracked for cleanup alongside the inline-style dialect they belong to.
     */
    private const array EXEMPT = [
        'cbx-testmode-strip',
        'cbx-strip-expand',
        'cbx-env-clone',
        'cbx-input--sm',
        'cbx-confirm-plane',
        'cbx-pricing',
        'cbx-pricing-card',
        'cbx-paywall',
        'cbx-upgrade',
    ];

    public function test_every_cbx_class_used_in_blade_is_defined_in_the_stylesheets(): void
    {
        $defined = $this->definedClasses();
        $this->assertNotSame([], $defined, 'No cbx-* rules found — the stylesheet paths are wrong.');

        $undefined = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = (string) file_get_contents($file);

            // Only `class="..."` attributes, so prose and comments mentioning a class name
            // cannot trip the check.
            preg_match_all('/class\s*=\s*"([^"]*)"/', $contents, $attributes);

            foreach ($attributes[1] as $attribute) {
                foreach (preg_split('/\s+/', $attribute) ?: [] as $class) {
                    if (! str_starts_with($class, 'cbx-') || str_contains($class, '{')) {
                        continue; // interpolated (`{{ }}`) classes are resolved at runtime
                    }

                    if (! in_array($class, $defined, true) && ! in_array($class, self::EXEMPT, true)) {
                        $undefined[$class][] = basename((string) $file);
                    }
                }
            }
        }

        $this->assertSame([], $undefined, $this->describe($undefined));
    }

    /** @return list<string> */
    private function definedClasses(): array
    {
        $classes = [];

        foreach (glob(public_path('cbox/**/*.css')) ?: [] as $sheet) {
            preg_match_all('/\.(cbx-[A-Za-z0-9_-]+)/', (string) file_get_contents($sheet), $matches);
            $classes = [...$classes, ...$matches[1]];
        }

        foreach (glob(public_path('cbox/*.css')) ?: [] as $sheet) {
            preg_match_all('/\.(cbx-[A-Za-z0-9_-]+)/', (string) file_get_contents($sheet), $matches);
            $classes = [...$classes, ...$matches[1]];
        }

        // Blade `<style>` blocks count as definitions: the public storefront, paywall and quote
        // pages are deliberately self-contained (CSP-safe, no external stylesheet), so their
        // rules legitimately live inline. Ignoring them would push working CSS out of the pages
        // that are designed to carry it.
        foreach ($this->bladeFiles() as $file) {
            preg_match_all('/<style[^>]*>(.*?)<\\/style>/s', (string) file_get_contents($file), $blocks);

            foreach ($blocks[1] as $block) {
                preg_match_all('/\\.(cbx-[A-Za-z0-9_-]+)/', $block, $matches);
                $classes = [...$classes, ...$matches[1]];
            }
        }

        return array_values(array_unique($classes));
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($directory as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @param  array<string, list<string>>  $undefined */
    private function describe(array $undefined): string
    {
        if ($undefined === []) {
            return '';
        }

        $lines = ['These cbx-* classes are used in Blade but defined in no stylesheet, so they render as nothing:'];

        foreach ($undefined as $class => $files) {
            $lines[] = sprintf('  .%s — %s', $class, implode(', ', array_unique($files)));
        }

        return implode("\n", $lines);
    }
}
