<?php

declare(strict_types=1);

use JeffersonGoncalves\QueueWorker\Exceptions\InvalidEnvironmentPathException;
use JeffersonGoncalves\QueueWorker\Support\PathValidator;

it('resolves a path inside the allowed root with a matching artisan file and slug', function (): void {
    $validator = new PathValidator(__DIR__.'/../Fixtures/environments');

    $resolved = $validator->resolve(__DIR__.'/../Fixtures/environments/app-feature-1234', 'app-feature-1234');

    expect($resolved)->toBe(realpath(__DIR__.'/../Fixtures/environments/app-feature-1234'));
});

it('rejects when allowed_root is not configured', function (): void {
    $validator = new PathValidator(null);

    expect(fn () => $validator->resolve(__DIR__.'/../Fixtures/environments/app-feature-1234', 'app-feature-1234'))
        ->toThrow(InvalidEnvironmentPathException::class);
});

it('rejects a path outside the allowed root', function (): void {
    $validator = new PathValidator(__DIR__.'/../Fixtures/environments');

    expect(fn () => $validator->resolve(__DIR__, 'Fixtures'))
        ->toThrow(InvalidEnvironmentPathException::class);
});

it('rejects a path traversal attempt', function (): void {
    $validator = new PathValidator(__DIR__.'/../Fixtures/environments');

    expect(fn () => $validator->resolve(__DIR__.'/../Fixtures/environments/app-feature-1234/../../../', 'app-feature-1234'))
        ->toThrow(InvalidEnvironmentPathException::class);
});

it('rejects a directory with no artisan file', function (): void {
    $validator = new PathValidator(__DIR__.'/../Fixtures/environments');

    expect(fn () => $validator->resolve(__DIR__.'/../Fixtures/environments', 'environments'))
        ->toThrow(InvalidEnvironmentPathException::class);
});

it('rejects when the resolved basename does not match the slug', function (): void {
    $validator = new PathValidator(__DIR__.'/../Fixtures/environments');

    expect(fn () => $validator->resolve(__DIR__.'/../Fixtures/environments/app-feature-1234', 'someone-elses-slug'))
        ->toThrow(InvalidEnvironmentPathException::class);
});
