<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use JeffersonGoncalves\QueueWorker\Exceptions\UnresolvedPhpBinaryException;
use JeffersonGoncalves\QueueWorker\PhpBinaryResolver;

beforeEach(function (): void {
    config(['queue-worker.php_binary_map' => [
        '8.2' => '/usr/bin/php8.2',
        '8.3' => '/usr/bin/php8.3',
    ]]);

    $this->environmentPath = __DIR__.'/../Fixtures/environments/app-feature-1234';
});

it('returns the configured binary for the environment composer.json php constraint', function (): void {
    $resolver = new PhpBinaryResolver(new Filesystem);

    expect($resolver->resolve($this->environmentPath))->toBe('/usr/bin/php8.2');
});

it('caches the resolved binary per path instead of re-reading composer.json', function (): void {
    $spy = Mockery::spy(Filesystem::class);
    $spy->shouldReceive('exists')->andReturn(true);
    $spy->shouldReceive('get')->andReturn((string) file_get_contents($this->environmentPath.'/composer.json'));

    $resolver = new PhpBinaryResolver($spy);

    $resolver->resolve($this->environmentPath);
    $resolver->resolve($this->environmentPath);
    $resolver->resolve($this->environmentPath);

    $spy->shouldHaveReceived('get')->once();
});

it('throws when no binary is configured for the constraint', function (): void {
    config(['queue-worker.php_binary_map' => [
        '8.3' => '/usr/bin/php8.3',
    ]]);

    $resolver = new PhpBinaryResolver(new Filesystem);

    expect(fn () => $resolver->resolve($this->environmentPath))
        ->toThrow(UnresolvedPhpBinaryException::class);
});

it('throws when the environment has no composer.json', function (): void {
    $resolver = new PhpBinaryResolver(new Filesystem);

    expect(fn () => $resolver->resolve(sys_get_temp_dir()))
        ->toThrow(UnresolvedPhpBinaryException::class);
});
