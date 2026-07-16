<?php

declare(strict_types=1);

use Cbox\LaravelHealth\Checks\CacheCheck;
use Cbox\LaravelHealth\Checks\DatabaseCheck;
use Cbox\LaravelHealth\Checks\QueueCheck;
use Cbox\LaravelHealth\Checks\StorageCheck;

return [

    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Health Checks
    |--------------------------------------------------------------------------
    */

    'enabled' => env('HEALTH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    */

    'endpoints' => [
        'prefix' => env('HEALTH_PREFIX', 'health'),
        'liveness' => ['path' => '/', 'enabled' => true],
        'readiness' => ['path' => '/ready', 'enabled' => true],
        'startup' => ['path' => '/startup', 'enabled' => true],
        'status' => ['path' => '/status', 'enabled' => true],
        'metrics' => ['path' => '/metrics', 'enabled' => true],
        'json' => ['path' => '/metrics/json', 'enabled' => true],
        'ui' => ['path' => '/ui', 'enabled' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    'security' => [
        // A shared token gates the detail-bearing endpoints (status / metrics / json).
        // Set HEALTH_TOKEN in production; supply it as `?token=` or a bearer header.
        'token' => env('HEALTH_TOKEN'),
        'allowed_ips' => env('HEALTH_ALLOWED_IPS') ? array_map('trim', explode(',', (string) env('HEALTH_ALLOWED_IPS'))) : null,

        // Liveness AND readiness are public: an orchestrator's probes (K8s livenessProbe
        // / readinessProbe) hit them unauthenticated, and they expose only up/down plus
        // the check names — never metric values or configuration. The status, prometheus
        // and json endpoints stay token-gated because they surface detail.
        'public_endpoints' => array_map('trim', explode(',', (string) env('HEALTH_PUBLIC_ENDPOINTS', 'liveness,readiness'))),
    ],

    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Health Checks per Endpoint
    |--------------------------------------------------------------------------
    */

    'checks' => [
        'liveness' => [
            DatabaseCheck::class,
        ],
        'readiness' => [
            DatabaseCheck::class,
            CacheCheck::class,
            QueueCheck::class,
            StorageCheck::class,
        ],
        'startup' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Check-Specific Configuration
    |--------------------------------------------------------------------------
    */

    'checks_config' => [
        'database' => ['connection' => null],
        'cache' => ['store' => null],
        'queue' => ['connection' => null],
        'storage' => ['disk' => 'local'],
        'redis' => ['connection' => 'default'],
        'environment' => ['required' => []],
        'schedule' => ['max_age_minutes' => 5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'prometheus' => [
            'enabled' => env('HEALTH_PROMETHEUS_ENABLED', true),
            'namespace' => env('HEALTH_PROMETHEUS_NAMESPACE', 'app'),
        ],
        'system' => [
            'memory' => true,
            'load' => true,
            'storage' => true,
            'network' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    */

    'thresholds' => [
        'disk_space_percent' => 90,
        'memory_percent' => 90,
        'cpu_load_per_core' => 2.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => env('HEALTH_CACHE_ENABLED', true),
        'ttl' => env('HEALTH_CACHE_TTL', 10),
        'store' => null,
    ],

];
