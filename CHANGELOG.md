# Changelog

All notable changes to `laravel-queue-worker` will be documented in this file.

## 1.3.0 - 2026-09-16

### Timeouts now reach the child process (#17)

`RunEnvironmentJob` never passed a timeout to `Process`, so every job was capped at Laravel's `PendingProcess` default of 60 seconds no matter what the originating job declared. Anything slower than a minute was unrunnable through the hub, and the failure read as a bug in the environment.

- the payload timeout is applied to the child process, and the hub job keeps it plus a 60-second margin, so the child is the one that times out first — Symfony kills the process and the `failed_jobs` row names the environment, instead of `pcntl_alarm` killing the worker and orphaning the child;
- a job queued before this version takes that margin out of the child's share instead, since the worker's alarm reads the timeout off the outer queue payload, which is already in Redis and cannot be rewritten;
- a payload declaring `timeout: 0` (Laravel's "no timeout") or a negative one is now rejected with `422` instead of silently running for one second;
- a payload whose timeout plus the margin reaches `retry_after` on the default queue connection is rejected with `422` naming both numbers, rather than being accepted and run twice concurrently.

### An outdated consumer now says so (#19)

An environment still on `laravel-queue-consumer` 1.1.0 rejects the `--queue` option the hub has passed since 1.2.0, and every job from it failed with `The "--queue" option does not exist.` — a Symfony message naming neither package nor version, for a fix that lives in a different directory.

- new `OutdatedEnvironmentConsumerException`, raised when the child's **stderr** carries that message, with the environment slug, its path and the `composer require jeffersongoncalves/laravel-queue-consumer:^1.2` to run there;
- only stderr is matched, where the argument parser fails before the command runs, so a job whose own output quotes that text is not mistaken for an outdated consumer.

### Upgrading

Environments must require `"jeffersongoncalves/laravel-queue-consumer": "^1.2"`. `^1.1` allows 1.2.0 but does not require it — an existing `composer.lock` keeps whatever 1.1.x it already resolved.

Check `retry_after` on the hub's queue connection before upgrading: it must exceed the longest timeout any environment declares plus 60 seconds, or those jobs are refused at `POST /api/jobs`. The README documents the full ordering.

## 1.2.0 - 2026-09-16

### What's Changed

> **Upgrade note:** this release requires `laravel-queue-consumer` **1.2.0 or newer** on the environment side — older consumers reject the new `--queue` option. It also rejects plain HTTP by default; see below.

#### Added

- **HTTPS is required by default.** `QUEUE_WORKER_REQUIRE_HTTPS` (default `true`) rejects any request that did not arrive over HTTPS with a `426 Upgrade Required`, before the token is compared. Over plain HTTP both the shared token and the payload — a serialized application job — travel in clear. Set it to `false` only when the transport is already private: loopback on a single host, or an encrypted private network such as WireGuard. Behind a TLS-terminating proxy, `TrustProxies` must trust that proxy or every request looks insecure.

#### Fixed

- **The originating queue name now survives a release.** `RunEnvironmentJob` carries the queue the environment posted and passes it as `queue-consumer:run --queue=<name>`, so a job that releases itself (`WithoutOverlapping`, `RateLimited`, `$this->release()`) returns to the queue it came from instead of silently landing on `default`. It is always the posted name, never the hub-side queue, which is the operator's routing decision (#15, #16).

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-queue-worker/compare/1.1.1...1.2.0

## 1.1.1 - 2026-09-16

### What's Changed

#### Fixed

- **The child process no longer inherits the hub's environment variables.** Symfony inherits the parent environment and Laravel publishes the hub's own `.env` into it, while the child bootstraps with `Dotenv::createImmutable`, which never overwrites what is already set — so every key the hub defined silently beat the environment's own `.env` inside the job: wrong database host, nested dispatches pushed into the hub's Redis under the hub's prefix, the hub's `APP_KEY`. Keys from both `.env` files are now passed to `Process::env()` as `false`, removing them from the child so its own Dotenv wins. `PATH` is preserved, case-insensitively (#13, #14).

No configuration change is required, and no `Env::disablePutenv()` on the hub side.

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-queue-worker/compare/1.1.0...1.1.1

## 1.1.0 - 2026-09-15

### What's Changed

#### Added

- **`QUEUE_WORKER_QUEUE`** — forces every incoming job onto a hub-side queue you control, instead of reusing the queue name posted by the environment. Left unset, behaviour is unchanged. Fixes jobs landing on a queue no Horizon supervisor watches, where they were stored and never consumed with no failed job and nothing on the dashboard (#6, #9).

#### Fixed

- **The hub's own directory is rejected by `PathValidator`.** When the hub is deployed inside `allowed_root`, its own directory satisfied every check, so a request carrying the hub's slug queued a job that ran `queue-consumer:run` inside the hub and failed with an opaque process error. It is now refused with `422` and a message naming the layout problem (#5, #8, #11).
- **Failed job messages include the child's stdout.** `queue-consumer:run` rethrows the job's exception and Laravel renders it through the console handler, which writes to stdout, so building the message from `errorOutput()` alone left `failed_jobs` rows ending at the colon. Both streams are now included, truncated to 4000 characters keeping head and tail (#7, #10).

#### Docs

- README documents the queue override, the hub-outside-`allowed_root` layout rule, and what a `failed_jobs` message now contains (#12).

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-queue-worker/compare/1.0.0...1.1.0

## 1.0.0 - 2026-09-05

Initial release.

Receives job payloads posted by [`laravel-queue-consumer`](https://github.com/jeffersongoncalves/laravel-queue-consumer) over an authenticated HTTP endpoint, queues them on the hub's own Horizon/Redis setup, and executes each job by spawning a child PHP process inside the originating environment's own directory — never deserializing the payload on the hub itself.

Path and token validation, plus per-`uuid` deduplication, guard the boundary between the incoming HTTP request and process execution.
