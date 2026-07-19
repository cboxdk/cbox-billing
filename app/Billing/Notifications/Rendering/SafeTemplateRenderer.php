<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Rendering;

use App\Billing\Notifications\Contracts\RendersTemplates;

/**
 * A restricted, sandboxed mustache/handlebars-style renderer — the safe path for rendering
 * operator- and file-authored templates that must NEVER be evaluated as Blade or PHP.
 *
 * WHY this and not Blade::render(): a stored template is data. Compiling it as Blade would
 * let `{{ … }}`/`@php` in the row execute arbitrary PHP with the app's privileges — a stored
 * RCE. This renderer only ever does string substitution + branching over a fixed grammar; it
 * calls no user-named function and evaluates no PHP, so a hostile template body (or a hostile
 * value inside a variable, e.g. a customer org name of `<script>…`) cannot execute code or,
 * for interpolation, inject markup — every interpolated value is HTML-escaped by default.
 *
 * Grammar (the whole of it):
 *   {{ path }}                 → escaped interpolation (dotted paths + `this` in a loop)
 *   {{#if path}}…{{/if}}       → render body when the value is truthy
 *   {{#unless path}}…{{/unless}} → render body when the value is falsy
 *   {{#each path}}…{{/each}}   → iterate a list; body scope is the item (`{{ this }}`/`{{ field }}`)
 *   {{else}}                   → optional alternate branch inside if/unless/each
 *
 * There is deliberately NO triple-mustache / raw-output form: a stored template can never
 * emit an unescaped value.
 */
class SafeTemplateRenderer implements RendersTemplates
{
    /**
     * Matches every `{{ … }}` tag. Group 1 is the sigil (`#`, `/`, or empty); group 2 is the
     * inner expression. `[^{}]*` never crosses a brace, so a stray `{` can't run away.
     */
    private const string TAG = '/\{\{\s*([#\/]?)\s*([^{}]*?)\s*\}\}/';

    public function render(string $template, array $variables, bool $escape = true): string
    {
        $nodes = $this->parse($this->tokenize($template));

        return $this->renderNodes($nodes, [$variables], $escape);
    }

    /**
     * Split the raw template into an ordered token stream of literal text and tags.
     *
     * @return list<array{type: 'text', value: string}|array{type: 'tag', sigil: string, expr: string}>
     */
    private function tokenize(string $template): array
    {
        $tokens = [];
        $offset = 0;

        preg_match_all(self::TAG, $template, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            $full = $match[0][0];
            $pos = $match[0][1];

            if ($pos > $offset) {
                $tokens[] = ['type' => 'text', 'value' => substr($template, $offset, $pos - $offset)];
            }

            $tokens[] = ['type' => 'tag', 'sigil' => $match[1][0], 'expr' => trim($match[2][0])];
            $offset = $pos + strlen($full);
        }

        if ($offset < strlen($template)) {
            $tokens[] = ['type' => 'text', 'value' => substr($template, $offset)];
        }

        return $tokens;
    }

    /**
     * Fold the flat token stream into a node tree. Section tags (`#if`/`#unless`/`#each`) open
     * a child list that runs until the matching `/…`; an `{{else}}` splits a section into its
     * primary and alternate branches. A close with no open section is ignored (fail-soft).
     *
     * @param  list<array{type: 'text', value: string}|array{type: 'tag', sigil: string, expr: string}>  $tokens
     * @return list<array<string, mixed>>
     */
    private function parse(array $tokens): array
    {
        $index = 0;

        return $this->parseNodes($tokens, $index, null)['primary'];
    }

    /**
     * @param  list<array{type: 'text', value: string}|array{type: 'tag', sigil: string, expr: string}>  $tokens
     * @return array{primary: list<array<string, mixed>>, alternate: list<array<string, mixed>>|null}
     */
    private function parseNodes(array $tokens, int &$index, ?string $stopFor): array
    {
        $primary = [];
        $alternate = null;
        $current = &$primary;

        while ($index < count($tokens)) {
            $token = $tokens[$index];

            if ($token['type'] === 'text') {
                $current[] = ['type' => 'text', 'value' => $token['value']];
                $index++;

                continue;
            }

            [$keyword, $argument] = $this->splitExpression($token['expr']);

            // Closing tag for the section we're inside: stop and hand control back.
            if ($token['sigil'] === '/') {
                if ($stopFor !== null && ($keyword === $stopFor || $keyword === '')) {
                    $index++;
                }

                break;
            }

            // `{{else}}` — begin the alternate branch of the enclosing section.
            if ($token['sigil'] === '' && $keyword === 'else') {
                $alternate = [];
                $current = &$alternate;
                $index++;

                continue;
            }

            // Section open — recurse for its body.
            if ($token['sigil'] === '#' && in_array($keyword, ['if', 'unless', 'each'], true)) {
                $index++;
                $body = $this->parseNodes($tokens, $index, $keyword);
                $current[] = [
                    'type' => 'section',
                    'kind' => $keyword,
                    'path' => $argument,
                    'body' => $body['primary'],
                    'alternate' => $body['alternate'],
                ];

                continue;
            }

            // Plain interpolation.
            $current[] = ['type' => 'var', 'path' => $token['expr']];
            $index++;
        }

        return ['primary' => $primary, 'alternate' => $alternate];
    }

    /**
     * Split a section expression like `if suspended` / `each lines` into [keyword, argument].
     * A bare interpolation expression yields ['', expr].
     *
     * @return array{0: string, 1: string}
     */
    private function splitExpression(string $expr): array
    {
        $parts = preg_split('/\s+/', $expr, 2);

        if ($parts === false || $parts[0] === '') {
            return ['', $expr];
        }

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<array-key, mixed>>  $scopes  Innermost scope last.
     */
    private function renderNodes(array $nodes, array $scopes, bool $escape): string
    {
        $out = '';

        foreach ($nodes as $node) {
            $out .= match ($node['type']) {
                'text' => is_string($node['value'] ?? null) ? $node['value'] : '',
                'var' => $this->interpolate(is_string($node['path'] ?? null) ? $node['path'] : '', $scopes, $escape),
                'section' => $this->renderSection($node, $scopes, $escape),
                default => '',
            };
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<array-key, mixed>>  $scopes
     */
    private function renderSection(array $node, array $scopes, bool $escape): string
    {
        $kind = is_string($node['kind'] ?? null) ? $node['kind'] : '';
        $path = is_string($node['path'] ?? null) ? $node['path'] : '';
        /** @var list<array<string, mixed>> $body */
        $body = is_array($node['body'] ?? null) ? $node['body'] : [];
        /** @var list<array<string, mixed>>|null $alternate */
        $alternate = is_array($node['alternate'] ?? null) ? $node['alternate'] : null;

        $value = $this->resolve($path, $scopes);

        if ($kind === 'each') {
            if (! is_array($value) || $value === []) {
                return $alternate !== null ? $this->renderNodes($alternate, $scopes, $escape) : '';
            }

            $out = '';
            foreach ($value as $item) {
                $frame = is_array($item) ? $item : [];
                $frame['this'] = $item;
                $out .= $this->renderNodes($body, [...$scopes, $frame], $escape);
            }

            return $out;
        }

        $truthy = $this->truthy($value);
        $render = $kind === 'unless' ? ! $truthy : $truthy;

        if ($render) {
            return $this->renderNodes($body, $scopes, $escape);
        }

        return $alternate !== null ? $this->renderNodes($alternate, $scopes, $escape) : '';
    }

    /**
     * @param  list<array<array-key, mixed>>  $scopes
     */
    private function interpolate(string $path, array $scopes, bool $escape): string
    {
        $value = $this->resolve($path, $scopes);
        $string = $this->stringify($value);

        return $escape ? htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $string;
    }

    /**
     * Look a dotted path up the scope stack (innermost first). `this` is the current loop
     * item. A missing key resolves to null — an unknown variable renders empty, never an error.
     *
     * @param  list<array<array-key, mixed>>  $scopes
     */
    private function resolve(string $path, array $scopes): mixed
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $head = $segments[0];

        for ($i = count($scopes) - 1; $i >= 0; $i--) {
            $scope = $scopes[$i];

            if (! array_key_exists($head, $scope)) {
                continue;
            }

            $value = $scope[$head];

            foreach (array_slice($segments, 1) as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];

                    continue;
                }

                return null;
            }

            return $value;
        }

        return null;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Booleans/arrays/null are structural (used by sections), not printable — render empty.
        return '';
    }
}
