<?php

declare(strict_types=1);

use JeffersonGoncalves\QueueWorker\PhpBinaryResolver;
use JeffersonGoncalves\QueueWorker\Support\PathValidator;

it('merges the package config', function (): void {
    expect(config('queue-worker.token'))->toBe('test-token');
    expect(config('queue-worker.route_prefix'))->toBe('api');
    expect(config('queue-worker.dedup_window_hours'))->toBe(24);
});

it('resolves the PhpBinaryResolver as a shared instance', function (): void {
    expect(app(PhpBinaryResolver::class))->toBe(app(PhpBinaryResolver::class));
});

it('resolves a fresh PathValidator reading the current allowed_root config', function (): void {
    config(['queue-worker.allowed_root' => '/srv/environments']);

    expect(app(PathValidator::class))->not->toBe(app(PathValidator::class));
});
