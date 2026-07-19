<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Notifications\Rendering\SafeTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The sandbox contract of the stored-template renderer: it interpolates + branches over a
 * fixed grammar and NOTHING else. It never evaluates PHP/Blade, escapes interpolated values by
 * default, and treats a value that itself looks like a template as literal text (no
 * re-rendering), so neither a hostile template body nor a hostile variable value can inject
 * markup or execute code.
 */
class SafeTemplateRendererTest extends TestCase
{
    private SafeTemplateRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new SafeTemplateRenderer;
    }

    public function test_interpolated_values_are_html_escaped(): void
    {
        $out = $this->renderer->render('Hi {{ name }}', ['name' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_a_value_that_looks_like_a_template_is_not_re_rendered(): void
    {
        // An injected value carrying template syntax (or PHP) is rendered as inert, escaped text.
        $out = $this->renderer->render('{{ evil }}', [
            'evil' => '{{ secret }}<?php echo "x"; ?>',
            'secret' => 'LEAKED',
        ]);

        $this->assertStringNotContainsString('LEAKED', $out);
        $this->assertStringNotContainsString('<?php', $out);
        $this->assertStringContainsString('secret', $out); // present only as escaped literal text
    }

    public function test_unknown_variables_render_empty_never_error(): void
    {
        $this->assertSame('a  b', $this->renderer->render('a {{ missing }} b', []));
    }

    public function test_if_else_branches_on_truthiness(): void
    {
        $tpl = '{{#if flag}}YES {{ label }}{{else}}NO{{/if}}';

        $this->assertSame('YES on', $this->renderer->render($tpl, ['flag' => true, 'label' => 'on']));
        $this->assertSame('NO', $this->renderer->render($tpl, ['flag' => false, 'label' => 'on']));
        // An empty string is falsy — a "may be empty" optional field disappears cleanly.
        $this->assertSame('NO', $this->renderer->render($tpl, ['flag' => '', 'label' => 'on']));
    }

    public function test_unless_is_the_inverse_of_if(): void
    {
        $tpl = '{{#unless suspended}}active{{/unless}}';

        $this->assertSame('active', $this->renderer->render($tpl, ['suspended' => false]));
        $this->assertSame('', $this->renderer->render($tpl, ['suspended' => true]));
    }

    public function test_each_iterates_a_list_with_item_scope(): void
    {
        $tpl = '{{#each rows}}[{{ label }}={{ this.value }}]{{/each}}';
        $out = $this->renderer->render($tpl, ['rows' => [
            ['label' => 'a', 'value' => '1'],
            ['label' => 'b', 'value' => '2'],
        ]]);

        $this->assertSame('[a=1][b=2]', $out);
    }

    public function test_each_over_an_empty_list_renders_its_else_branch(): void
    {
        $tpl = '{{#each rows}}{{ x }}{{else}}none{{/each}}';

        $this->assertSame('none', $this->renderer->render($tpl, ['rows' => []]));
    }

    public function test_plain_text_mode_does_not_escape_but_still_never_executes(): void
    {
        // The subject/plain-text path: no HTML escaping, but still pure substitution.
        $out = $this->renderer->render('Invoice {{ n }}', ['n' => 'A&B'], false);

        $this->assertSame('Invoice A&B', $out);
    }
}
