<div class="filament-hidden">

![Laravel Queue Worker](https://raw.githubusercontent.com/jeffersongoncalves/laravel-queue-worker/main/art/jeffersongoncalves-laravel-queue-worker.png)

</div>

# Laravel Queue Worker

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-queue-worker.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-queue-worker)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-queue-worker/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-queue-worker/actions/workflows/tests.yml)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-queue-worker/pint.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/jeffersongoncalves/laravel-queue-worker/actions/workflows/pint.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-queue-worker.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-queue-worker)
[![License](https://img.shields.io/packagist/l/jeffersongoncalves/laravel-queue-worker.svg?style=flat-square)](LICENSE.md)

Receive Laravel queue job payloads over HTTP from many ephemeral review environments and execute them, each inside its own originating environment, from a central "hub" application backed by Horizon.

This is the **hub-side** counterpart to [`jeffersongoncalves/laravel-queue-consumer`](https://github.com/jeffersongoncalves/laravel-queue-consumer). At least one environment running that package is required to send this package any jobs — installing this package alone receives nothing on its own.

## How it works

Dozens of ephemeral review environments (one Laravel application per git branch, spun up and torn down by CI, each with its own `vendor/`, `.env`, and its own version of the application code) run `laravel-queue-consumer`, which POSTs job payloads here instead of running a local queue worker.

This package:

1. Accepts `POST /api/jobs`, authenticates it, and validates the environment `path` it was given.
2. Queues a `RunEnvironmentJob` onto Horizon/Redis — nothing runs synchronously inside the HTTP request.
3. When that job runs, it spawns a child PHP process **inside the originating environment's own directory**, running `artisan queue-consumer:run` there, so the job executes with that environment's own autoloader, code, and database connection — never inside this hub application's own process.

## Installation

You can install the package via composer:

```bash
composer require jeffersongoncalves/laravel-queue-worker
```

The package uses Laravel's auto-discovery, so the service provider is registered automatically.

### Publish configuration

```bash
php artisan vendor:publish --tag=queue-worker-config
```

This publishes `config/queue-worker.php`:

```php
return [
    'token' => env('QUEUE_WORKER_TOKEN'),

    'allowed_root' => env('QUEUE_WORKER_ALLOWED_ROOT'),

    'route_prefix' => env('QUEUE_WORKER_ROUTE_PREFIX', 'api'),

    'route_middleware' => ['api'],

    'dedup_window_hours' => env('QUEUE_WORKER_DEDUP_WINDOW_HOURS', 24),

    'php_binary_map' => [
        // '8.1' => '/usr/bin/php8.1',
        // '8.2' => '/usr/bin/php8.2',
        // '8.3' => '/usr/bin/php8.3',
    ],
];
```

### Environment setup

```env
QUEUE_WORKER_TOKEN=some-shared-secret-token
QUEUE_WORKER_ALLOWED_ROOT=/srv/environments
QUEUE_WORKER_DEDUP_WINDOW_HOURS=24
```

**`QUEUE_WORKER_TOKEN` has no default and is required.** The `X-Laravel-Queue-Token` header of every incoming request is compared against it using a constant-time `hash_equals()` check. If the header is missing or wrong, the request is rejected with `403`. If the token itself is left unconfigured, the package refuses to process *any* request at all — it never falls back to accepting unauthenticated traffic.

**`QUEUE_WORKER_ALLOWED_ROOT` has NO default value on purpose, and this is a loud warning: you must set it.** Every incoming `path` is resolved with `realpath()` and must live inside this directory, must contain an `artisan` file directly inside it, and its basename must match the request's `slug` field (i.e. an environment at `/srv/environments/app-feature-1234` must be posted with `"slug": "app-feature-1234"`). If `allowed_root` is not configured, every single request is rejected with `422` — the package never falls back to "allow everything" or "allow the current working directory".

`php_binary_map` maps an environment's own `composer.json` `require.php` constraint (major.minor, e.g. `8.2` from `^8.2`) to a concrete PHP binary on the host running this package. There is no fallback to this hub's own `php` binary: an environment whose PHP version isn't mapped causes the job to throw loudly, rather than silently running someone else's job under the wrong PHP version.

### Optional: distinguishing failed jobs by environment

This package can optionally add nullable `slug` and `display_name` columns to your `failed_jobs` table, so failed jobs from many environments are distinguishable in a listing without cross-referencing `path`. This migration is **not run automatically** — publish and run it yourself if you want it:

```bash
php artisan vendor:publish --tag=queue-worker-migrations
php artisan migrate
```

The migration is written defensively: it checks whether `failed_jobs` exists and whether each column already exists before adding it.

## Protocol

`POST {route_prefix}/jobs` (default `/api/jobs`), with header `X-Laravel-Queue-Token`:

```json
{
    "slug": "app-feature-1234",
    "path": "/srv/environments/app-feature-1234",
    "queue": "default",
    "delay": 0,
    "payload": "<opaque string, exactly what Laravel's own createPayload() produced in the originating environment>"
}
```

A successful response is `202` with `{"id": "<hub job id>"}` (the `uuid` read off the payload). Delivery is at-least-once: the consumer package may resend an identical request if it loses the response, so this package deduplicates by the payload's `uuid` for `dedup_window_hours` (default 24h).

This package never deserializes the job payload itself. It only reads the outer bookkeeping fields via `json_decode` (`uuid`, `displayName`, `maxTries`, `timeout`) and forwards the payload string, untouched, to the child process — where the consumer package's own `queue-consumer:run` command unserializes it, in the originating environment, with that environment's own autoloader.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security

**This package is for internal, trusted review environments only.** It must never be exposed to the public internet and must never run in production. `allowed_root` and the token are the only things standing between an HTTP request and arbitrary process execution on the host running this package — treat both accordingly. Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Limitations

- No built-in dashboard — use Horizon's own dashboard for queue visibility and job tags (`env:<slug>`, `job:<displayName>`).
- No discovery or garbage collection of stale environment directories: if an environment is torn down, its queued jobs are simply discarded once they run and find the path gone.
- No support for non-Laravel consumers — the protocol assumes the sender is `jeffersongoncalves/laravel-queue-consumer`.

## Credits

- [Jefferson Gonçalves](https://github.com/jeffersongoncalves)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
