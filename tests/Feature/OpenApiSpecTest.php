<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The OpenAPI contract's guard rails. The spec at docs/openapi/cbox-billing.yaml is the
 * published contract for the enforcement + management API; these tests keep it honest:
 *
 *  - it parses as OpenAPI 3.1 and is structurally well-formed;
 *  - the committed JSON projection is in sync with the YAML source of truth;
 *  - every `/api/v1` route is documented AND every documented path is a real route
 *    (the drift guard, both directions — the contract can't silently rot);
 *  - every operation carries an operationId, typed responses, and realistic examples;
 *  - the three serving endpoints answer with the right content types, and the docs page
 *    is fully self-contained (no external hosts — CSP-safe).
 */
class OpenApiSpecTest extends TestCase
{
    private const string YAML = 'docs/openapi/cbox-billing.yaml';

    private const string JSON = 'docs/openapi/cbox-billing.json';

    /** @var list<string> */
    private const array HTTP_METHODS = ['get', 'post', 'put', 'delete', 'patch'];

    /** @return array<string, mixed> */
    private function spec(): array
    {
        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile(base_path(self::YAML));

        return $spec;
    }

    public function test_spec_parses_as_openapi_3_1(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi'], 'The spec must declare OpenAPI 3.1.0.');
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('schemas', $spec['components']);
        $this->assertArrayHasKey('securitySchemes', $spec['components']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        $this->assertNotEmpty($spec['servers']);
    }

    public function test_committed_json_is_in_sync_with_the_yaml_source(): void
    {
        $fromYaml = json_encode($this->spec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $committed = file_get_contents(base_path(self::JSON));

        $this->assertSame(
            $fromYaml,
            $committed,
            'docs/openapi/cbox-billing.json is stale — run `composer openapi:json` after editing the YAML.',
        );
    }

    public function test_every_api_v1_route_is_documented_and_no_phantom_paths(): void
    {
        $specOps = $this->specOperations();
        $routeOps = $this->routeOperations();

        $undocumented = array_diff($routeOps, $specOps);
        $phantom = array_diff($specOps, $routeOps);

        $this->assertSame(
            [],
            array_values($undocumented),
            'These /api/v1 routes are missing from the OpenAPI spec: '.implode(', ', $undocumented),
        );

        $this->assertSame(
            [],
            array_values($phantom),
            'These OpenAPI paths do not correspond to a real /api/v1 route: '.implode(', ', $phantom),
        );

        // Sanity: the full surface is covered (not an accidentally-empty comparison).
        $this->assertGreaterThanOrEqual(35, count($specOps));
        $this->assertCount(count($routeOps), $specOps);
    }

    public function test_every_operation_has_an_id_responses_and_examples(): void
    {
        $spec = $this->spec();
        $seenOperationIds = [];

        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }

                $label = strtoupper($method).' '.$path;

                $this->assertArrayHasKey('operationId', $operation, "{$label} is missing an operationId.");
                $this->assertNotContains($operation['operationId'], $seenOperationIds, "Duplicate operationId {$operation['operationId']}.");
                $seenOperationIds[] = $operation['operationId'];

                $this->assertArrayHasKey('tags', $operation, "{$label} is untagged.");
                $this->assertArrayHasKey('summary', $operation, "{$label} has no summary.");
                $this->assertArrayHasKey('responses', $operation, "{$label} has no responses.");

                // A 2xx response with a body must carry a realistic example.
                $successCodes = array_filter(array_keys($operation['responses']), static fn ($c): bool => str_starts_with((string) $c, '2'));
                $this->assertNotEmpty($successCodes, "{$label} has no 2xx response.");

                foreach ($successCodes as $code) {
                    $response = $operation['responses'][$code];
                    if (! isset($response['content']['application/json'])) {
                        continue; // e.g. 204 No Content
                    }
                    $this->assertTrue(
                        $this->hasExample($response['content']['application/json']),
                        "{$label} response {$code} has no example.",
                    );
                }

                // A request body must carry an example too.
                if (isset($operation['requestBody']['content']['application/json'])) {
                    $this->assertTrue(
                        $this->hasExample($operation['requestBody']['content']['application/json']),
                        "{$label} request body has no example.",
                    );
                }

                // Every auth-required operation documents the real error shapes.
                $isPublic = isset($operation['security']) && $operation['security'] === [];
                if (! $isPublic) {
                    $this->assertArrayHasKey('401', $operation['responses'], "{$label} does not document 401.");
                }
            }
        }
    }

    public function test_openapi_yaml_endpoint_serves(): void
    {
        $response = $this->get('/api/openapi.yaml');

        $response->assertOk();
        $this->assertStringContainsString('application/yaml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('openapi: 3.1.0', $response->getContent());
    }

    public function test_openapi_json_endpoint_serves_valid_json(): void
    {
        $response = $this->get('/api/openapi.json');

        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $decoded = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('3.1.0', $decoded['openapi']);
    }

    public function test_docs_page_serves_and_is_self_contained(): void
    {
        $response = $this->get('/api/docs');

        $response->assertOk();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));

        $html = (string) $response->getContent();

        // The embedded spec data block is inert; strip it before checking for external
        // asset loads so a `format: uri` example inside the spec can't trip the assertion.
        $markup = (string) preg_replace('#<script type="application/json".*?</script>#s', '', $html);

        $this->assertDoesNotMatchRegularExpression('#(src|href)\s*=\s*"https?://#i', $markup, 'The docs page must not load external assets.');
        $this->assertStringNotContainsString('@import', $markup);
        $this->assertStringNotContainsString('url(http', $markup);
        $this->assertStringContainsString('Cbox Billing API', $html);
    }

    /**
     * The set of documented operations, as `METHOD /path` (path relative to the
     * `/api/v1` server base, matching the route form).
     *
     * @return list<string>
     */
    private function specOperations(): array
    {
        $ops = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $_) {
                if (in_array($method, self::HTTP_METHODS, true)) {
                    $ops[] = strtoupper($method).' '.$path;
                }
            }
        }

        sort($ops);

        return $ops;
    }

    /**
     * The set of real `/api/v1` routes, as `METHOD /path` with the `/api/v1` prefix
     * stripped so it lines up with the spec's server-relative paths.
     *
     * @return list<string>
     */
    private function routeOperations(): array
    {
        $ops = [];

        /** @var IlluminateRoute $route */
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $path = substr($uri, strlen('api/v1'));

            foreach ($route->methods() as $method) {
                if (in_array(strtolower($method), self::HTTP_METHODS, true)) {
                    $ops[] = strtoupper($method).' '.$path;
                }
            }
        }

        $ops = array_values(array_unique($ops));
        sort($ops);

        return $ops;
    }

    /**
     * Whether a media type carries a usable example — either inline on the media type, or
     * on the schema it references (a schema-level `example` reused across every operation
     * that returns it).
     *
     * @param  array<string, mixed>  $mediaType
     */
    private function hasExample(array $mediaType): bool
    {
        if (isset($mediaType['example'])) {
            return true;
        }

        if (isset($mediaType['examples']) && is_array($mediaType['examples']) && $mediaType['examples'] !== []) {
            return true;
        }

        $schema = $mediaType['schema'] ?? null;

        if (is_array($schema) && isset($schema['$ref']) && is_string($schema['$ref'])) {
            $schema = $this->resolveRef($schema['$ref']);
        }

        return is_array($schema) && isset($schema['example']);
    }

    /**
     * Resolve a local `#/...` JSON pointer against the spec.
     *
     * @return array<string, mixed>|null
     */
    private function resolveRef(string $ref): ?array
    {
        $node = $this->spec();

        foreach (explode('/', ltrim($ref, '#/')) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_array($node) ? $node : null;
    }
}
