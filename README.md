<div class="filament-hidden">

![Laravel Queue Worker](https://raw.githubusercontent.com/jeffersongoncalves/laravel-queue-worker/main/art/jeffersongoncalves-laravel-queue-worker.png)

</div>

# Laravel Queue Worker

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-FFDD00?style=flat-square&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/jeffersongoncalves)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-queue-worker.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-queue-worker)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-queue-worker/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-queue-worker/actions/workflows/tests.yml)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-queue-worker/pint.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/jeffersongoncalves/laravel-queue-worker/actions/workflows/pint.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-queue-worker.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-queue-worker)
[![License](https://img.shields.io/packagist/l/jeffersongoncalves/laravel-queue-worker.svg?style=flat-square)](LICENSE.md)

Receive Laravel queue job payloads over HTTP from many ephemeral review environments and execute them, each inside its own originating environment, from a central "hub" application backed by Horizon.

This is the **hub-side** counterpart to [`jeffersongoncalves/laravel-queue-consumer`](https://github.com/jeffersongoncalves/laravel-queue-consumer). At least one environment running that package is required to send this package any jobs — installing this package alone receives nothing on its own.

> **Requires `laravel-queue-consumer` 1.2.0 or newer on the environment side.** From 1.2.0 this package passes `--queue=` to `queue-consumer:run`, an option older consumers do not accept.

## How it works

Dozens of ephemeral review environments (one Laravel application per git branch, spun up and torn down by CI, each with its own `vendor/`, `.env`, and its own version of the application code) run `laravel-queue-consumer`, which POSTs job payloads here instead of running a local queue worker.

This package:

1. Accepts `POST /api/jobs`, authenticates it, and validates the environment `path` it was given.
2. Queues a `RunEnvironmentJob` onto Horizon/Redis — nothing runs synchronously inside the HTTP request.
3. When that job runs, it spawns a child PHP process **inside the originating environment's own directory**, running `artisan queue-consumer:run` there, so the job executes with that environment's own autoloader, code, and database connection — never inside this hub application's own process.

Every key defined in this hub's `.env` and in the environment's `.env` is removed from the child process environment before it starts. A child inherits the parent environment, and Laravel publishes the hub's own `.env` values into it, while the child's `Dotenv::createImmutable` never overwrites what is already set — so without the scrub the hub's `DB_*`, `REDIS_*`, `QUEUE_CONNECTION` and `APP_KEY` would silently beat the environment's own. `PATH` and the rest of the shell environment are left alone.

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

    'queue' => env('QUEUE_WORKER_QUEUE'),

    'require_https' => env('QUEUE_WORKER_REQUIRE_HTTPS', true),

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
QUEUE_WORKER_QUEUE=environments
QUEUE_WORKER_REQUIRE_HTTPS=true
QUEUE_WORKER_DEDUP_WINDOW_HOURS=24
```

**`QUEUE_WORKER_TOKEN` has no default and is required.** The `X-Laravel-Queue-Token` header of every incoming request is compared against it using a constant-time `hash_equals()` check. If the header is missing or wrong, the request is rejected with `403`. If the token itself is left unconfigured, the package refuses to process *any* request at all — it never falls back to accepting unauthenticated traffic.

**`QUEUE_WORKER_ALLOWED_ROOT` has NO default value on purpose, and this is a loud warning: you must set it.** Every incoming `path` is resolved with `realpath()` and must live inside this directory, must contain an `artisan` file directly inside it, and its basename must match the request's `slug` field (i.e. an environment at `/srv/environments/app-feature-1234` must be posted with `"slug": "app-feature-1234"`). If `allowed_root` is not configured, every single request is rejected with `422` — the package never falls back to "allow everything" or "allow the current working directory".

The hub itself should live **outside** `allowed_root`. If it doesn't (hub at `/srv/environments/hub`, environments at `/srv/environments/<slug>`), a request pointing at the hub's own directory passes every other check; the package rejects it explicitly with `422` rather than queueing a job that runs `queue-consumer:run` inside the hub and fails later.

**`QUEUE_WORKER_QUEUE` decides which hub-side queue every job lands on.** Left unset, the hub reuses the queue name posted by the environment — and since that name comes from applications the hub operator does not control, a job posted to a queue no Horizon supervisor watches is stored and never consumed: `202` to the consumer, no failed job, nothing on the dashboard. Set it to a queue your supervisor actually watches and every incoming job is forced onto it. This is safe: the hub-side queue only decides which hub worker picks up `RunEnvironmentJob`; the payload is forwarded untouched and carries the job's own queue name for the environment side.

**`QUEUE_WORKER_REQUIRE_HTTPS` defaults to `true`** and rejects any request that did not arrive over HTTPS with a `426 Upgrade Required`, before the token is even compared. The token and the payload both travel in clear over plain HTTP, and the payload is a serialized job — usually your application's own data.

Turn it off **only** when the transport is already private:

- hub and environments on the same host, posting to `http://127.0.0.1` — no network, nothing to intercept;
- an encrypted private network (WireGuard, Tailscale, an SSH tunnel), which is usually the easiest option for ephemeral review environments, since issuing a certificate per environment is friction CI does not need.

Otherwise terminate TLS in front of the hub (an internal CA is enough; mTLS if you also want to authenticate the environment).

> **Behind a TLS-terminating proxy**, `$request->secure()` is only `true` once Laravel's `TrustProxies` middleware trusts that proxy (`TRUSTED_PROXIES`). Without it a correctly configured HTTPS setup is rejected with `426`.

`php_binary_map` maps an environment's own `composer.json` `require.php` constraint (major.minor, e.g. `8.2` from `^8.2`) to a concrete PHP binary on the host running this package. There is no fallback to this hub's own `php` binary: an environment whose PHP version isn't mapped causes the job to throw loudly, rather than silently running someone else's job under the wrong PHP version.

### Timeouts

The child process runs with the timeout the originating job declared (`timeout` on the payload, default `60`), and the hub-side `RunEnvironmentJob` gets that value plus a 60-second margin. The margin matters: both numbers come from the same payload field, so without it they expire together and it is a coin toss which fires first. The child timing out first is the path worth having — Symfony kills it, the job fails with a `ProcessTimedOutException`, and the `failed_jobs` row names the environment. The other way round, the worker's `pcntl_alarm` kills the hub job mid-run, leaving an orphan child process and `has been attempted too many times or run too long` as the only clue.

A job that was already queued before this version takes the margin out of the child's share instead, since the worker's alarm reads the timeout off the outer queue payload, written at dispatch and no longer rewritable. Below 60 seconds there is no room to take and the two expire together, exactly as they did before the upgrade.

Two hub-side knobs still cap the chain and cannot be derived per job, so set them above the longest timeout any environment declares:

- the Horizon supervisor's `timeout` in `config/horizon.php` — only a fallback, since `Worker::timeoutForJob()` prefers the job's own `$timeout`;
- `retry_after` on the Redis connection in `config/queue.php`, which **must** exceed the longest job timeout, or the job is reclaimed and duplicated while still running.

The required order:

```
child timeout (payload)  <  job timeout (payload + 60s)  <  supervisor timeout  <  retry_after
```

### Optional: distinguishing failed jobs by environment

This package can optionally add nullable `slug` and `display_name` columns to your `failed_jobs` table, so failed jobs from many environments are distinguishable in a listing without cross-referencing `path`. This migration is **not run automatically** — publish and run it yourself if you want it:

```bash
php artisan vendor:publish --tag=queue-worker-migrations
php artisan migrate
```

The migration is written defensively: it checks whether `failed_jobs` exists and whether each column already exists before adding it.

### Reading a failed job

When the child process exits non-zero, the hub throws `EnvironmentProcessFailedException` and Laravel stores its message on the `failed_jobs` row. The message carries the exit code plus **both** the child's stderr and stdout — `queue-consumer:run` rethrows the job's own exception and Laravel renders it through the console handler, which writes to stdout, so stderr alone is usually empty.

The combined output is truncated to 4000 characters, keeping the head (the rendered exception: class, message, file and line) and the tail, with a `[... truncated ...]` marker between them, so a long stack trace never fills the row.

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

`queue` is the hub-side queue the job is placed on, unless `QUEUE_WORKER_QUEUE` is configured, which overrides it. `delay` is honored as posted.

The posted `queue` also travels with the job and is passed to the environment as `queue-consumer:run --queue=<name>`, so a job that releases itself (`WithoutOverlapping`, `RateLimited`, a plain `$this->release()`) is re-posted to the hub announcing the queue it came from instead of silently changing lane. This is always the **posted** name, never the hub-side one: the hub-side queue is the operator's routing decision, the posted name carries the originating application's semantics.

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

## Security Vulnerabilities

**This package is for internal, trusted review environments only.** It must never be exposed to the public internet and must never run in production. `allowed_root`, the token and the transport are the only things standing between an HTTP request and arbitrary process execution on the host running this package — treat all three accordingly, and leave `QUEUE_WORKER_REQUIRE_HTTPS` on unless the network is already private. Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Limitations

- No built-in dashboard — use Horizon's own dashboard for queue visibility and job tags (`env:<slug>`, `job:<displayName>`).
- No discovery or garbage collection of stale environment directories: if an environment is torn down, its queued jobs are simply discarded once they run and find the path gone.
- No support for non-Laravel consumers — the protocol assumes the sender is `jeffersongoncalves/laravel-queue-consumer`.

## Credits

- [Jefferson Gonçalves](https://github.com/jeffersongoncalves)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
