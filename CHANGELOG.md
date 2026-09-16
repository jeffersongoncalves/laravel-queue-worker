# Changelog

All notable changes to `laravel-queue-worker` will be documented in this file.

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
