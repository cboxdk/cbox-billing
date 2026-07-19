<?php

declare(strict_types=1);

/*
 * Generate docs/openapi/cbox-billing.json from the hand-authored YAML source of truth.
 *
 * The YAML file (docs/openapi/cbox-billing.yaml) is authored by hand; the JSON is a
 * derived artifact served at GET /api/openapi.json. Runtime serving reads these files
 * raw — symfony/yaml is a DEV-ONLY dependency used by this build script and the spec
 * drift test, never at runtime. Re-run after editing the YAML; CI fails on drift.
 *
 *   composer openapi:json      (php bin/openapi-json.php)
 */

use Symfony\Component\Yaml\Yaml;

require __DIR__.'/../vendor/autoload.php';

$yamlPath = __DIR__.'/../docs/openapi/cbox-billing.yaml';
$jsonPath = __DIR__.'/../docs/openapi/cbox-billing.json';

if (! is_file($yamlPath)) {
    fwrite(STDERR, "Spec not found: {$yamlPath}\n");
    exit(1);
}

$spec = Yaml::parseFile($yamlPath);

$json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    fwrite(STDERR, 'Failed to encode spec as JSON: '.json_last_error_msg()."\n");
    exit(1);
}

file_put_contents($jsonPath, $json."\n");

$operations = 0;
foreach ($spec['paths'] as $ops) {
    foreach (array_keys($ops) as $method) {
        if (in_array($method, ['get', 'post', 'put', 'delete', 'patch'], true)) {
            $operations++;
        }
    }
}

printf("Wrote %s: OpenAPI %s, %d paths, %d operations.\n", basename($jsonPath), $spec['openapi'], count($spec['paths']), $operations);
