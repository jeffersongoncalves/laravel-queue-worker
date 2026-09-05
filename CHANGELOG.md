# Changelog

All notable changes to `laravel-queue-worker` will be documented in this file.

## 1.0.0 - 2026-09-05

Initial release.

Receives job payloads posted by [`laravel-queue-consumer`](https://github.com/jeffersongoncalves/laravel-queue-consumer) over an authenticated HTTP endpoint, queues them on the hub's own Horizon/Redis setup, and executes each job by spawning a child PHP process inside the originating environment's own directory — never deserializing the payload on the hub itself.

Path and token validation, plus per-`uuid` deduplication, guard the boundary between the incoming HTTP request and process execution.
