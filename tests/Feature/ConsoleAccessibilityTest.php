<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Structural accessibility guarantees that are cheap to assert and expensive to lose.
 *
 * None of this is catchable by a rendering test — the markup was valid and every page returned
 * 200 while a screen-reader user could not read a single list.
 */
class ConsoleAccessibilityTest extends TestCase
{
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

    public function test_no_table_row_claims_to_be_a_link(): void
    {
        // `role="link"` on a <tr> does two things, both bad. It overrides the row's `row` role,
        // which collapses the table's row/column relationships for the WHOLE table; and because
        // a link takes its accessible name from the author, the paired aria-label REPLACES the
        // subtree — so the customer, date, amount and status in that row become unreachable.
        //
        // It was on 30 rows across 27 files: every list in the console. A screen-reader operator
        // on /console/invoices heard "Open invoice INV-1042, link" and nothing else — no way to
        // learn what any invoice was worth or whether it was paid.
        //
        // The rows stay focusable with `tabindex` and keep their aria-label (which names a ROW
        // without hiding it), and the existing keydown handler still navigates on Enter.
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            if (str_contains((string) file_get_contents($file), 'role="link"')) {
                $offenders[] = basename((string) $file);
            }
        }

        $this->assertSame([], $offenders, 'role="link" collapses table semantics: '.implode(', ', $offenders));
    }

    public function test_both_layouts_expose_a_main_landmark(): void
    {
        // `<main>` appeared exactly once in the entire application, on the paywall. Without it
        // there is no landmark to jump to, so screen-reader landmark navigation finds nothing
        // and the only way to the content is to tab through all of the chrome.
        foreach (['layouts/app', 'layouts/hosted'] as $layout) {
            $contents = (string) file_get_contents(resource_path("views/{$layout}.blade.php"));

            $this->assertStringContainsString('<main', $contents, "{$layout} must expose a <main> landmark.");
            $this->assertStringContainsString('id="content"', $contents, "{$layout}'s main must be a skip-link target.");
        }
    }

    public function test_the_console_offers_a_skip_link_as_the_first_focusable_element(): void
    {
        $contents = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $body = strpos($contents, '<body');
        $skip = strpos($contents, 'cbx-skip');
        $nav = strpos($contents, '<aside');

        $this->assertNotFalse($skip, 'The console needs a skip link — reaching the first row costs ~25 tab stops.');
        $this->assertNotFalse($body);
        $this->assertTrue($skip > $body, 'The skip link belongs inside <body>.');

        if ($nav !== false) {
            $this->assertTrue($skip < $nav, 'The skip link must come BEFORE the navigation, or it bypasses nothing.');
        }
    }
}
