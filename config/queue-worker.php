<?php

declare(strict_types=1);

return [
    /*
     * Compared against the X-Laravel-Queue-Token header using hash_equals().
     * The package refuses to boot if this is left blank.
     */
    'token' => env('QUEUE_WORKER_TOKEN'),

    /*
     * Every incoming "path" must resolve, via realpath(), to somewhere inside
     * this directory. There is NO default value on purpose: leaving this
     * unconfigured makes the package reject every single request.
     */
    'allowed_root' => env('QUEUE_WORKER_ALLOWED_ROOT'),

    'route_prefix' => env('QUEUE_WORKER_ROUTE_PREFIX', 'api'),

    'route_middleware' => ['api'],

    /*
     * How long a job "uuid" is remembered for, to protect against the
     * consumer package's at-least-once delivery resending the same payload.
     */
    'dedup_window_hours' => env('QUEUE_WORKER_DEDUP_WINDOW_HOURS', 24),

    /*
     * Maps an environment's composer.json "require.php" major.minor version
     * to a concrete PHP binary on this host. No fallback is used: an
     * unmapped version throws instead of silently running the hub's own PHP.
     */
    'php_binary_map' => [
        // '8.1' => '/usr/bin/php8.1',
        // '8.2' => '/usr/bin/php8.2',
        // '8.3' => '/usr/bin/php8.3',
    ],
];
