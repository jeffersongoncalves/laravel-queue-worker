<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JeffersonGoncalves\QueueWorker\Exceptions\EnvironmentProcessFailedException;
use JeffersonGoncalves\QueueWorker\Jobs\RunEnvironmentJob;
use JeffersonGoncalves\QueueWorker\PhpBinaryResolver;
use JeffersonGoncalves\QueueWorker\Tests\Fixtures\PayloadFactory;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeEnvironmentJob(array $overrides = []): RunEnvironmentJob
{
    $data = array_merge([
        'slug' => 'app-feature-1234',
        'path' => __DIR__.'/../Fixtures/environments/app-feature-1234',
        'payload' => PayloadFactory::make(),
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\GenerateInvoice',
        'maxTries' => 3,
        'timeout' => 1800,
    ], $overrides);

    return new RunEnvironmentJob(
        slug: $data['slug'],
        path: $data['path'],
        payload: $data['payload'],
        uuid: $data['uuid'],
        displayName: $data['displayName'],
        maxTries: $data['maxTries'],
        timeout: $data['timeout'],
    );
}

beforeEach(function (): void {
    config(['queue-worker.php_binary_map' => ['8.2' => 'php']]);
});

it('runs the child process inside the environment path with the base64-encoded payload', function (): void {
    Process::fake();

    $job = makeEnvironmentJob();

    $job->handle(app(PhpBinaryResolver::class));

    Process::assertRan(fn ($process): bool => $process->command === [
        'php', 'artisan', 'queue-consumer:run', '--payload='.base64_encode($job->payload),
    ]);
});

it('passes --last-attempt once the job has reached its final try', function (): void {
    Process::fake();

    $job = makeEnvironmentJob(['maxTries' => 1]);

    $job->handle(app(PhpBinaryResolver::class));

    Process::assertRan(fn ($process): bool => in_array('--last-attempt', $process->command, true));
});

it('throws when the child process exits non-zero, letting the queue mark it failed', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);

    $job = makeEnvironmentJob();

    expect(fn () => $job->handle(app(PhpBinaryResolver::class)))
        ->toThrow(EnvironmentProcessFailedException::class);
});

it('discards the job without throwing when the environment path no longer exists', function (): void {
    Process::fake();

    $job = makeEnvironmentJob(['path' => sys_get_temp_dir().'/queue-worker-torn-down-'.uniqid()]);

    $job->handle(app(PhpBinaryResolver::class));

    Process::assertNothingRan();
});

it('derives tries and timeout from the payload instead of a hardcoded constant', function (): void {
    $jobA = makeEnvironmentJob(['maxTries' => 2, 'timeout' => 120]);
    $jobB = makeEnvironmentJob(['maxTries' => 9, 'timeout' => 3600]);

    expect($jobA->tries)->toBe(2);
    expect($jobA->timeout)->toBe(120);
    expect($jobB->tries)->toBe(9);
    expect($jobB->timeout)->toBe(3600);
});

it('exposes environment and job tags for Horizon', function (): void {
    $job = makeEnvironmentJob(['slug' => 'app-feature-9999', 'displayName' => 'App\\Jobs\\SendReceipt']);

    expect($job->tags())->toBe(['env:app-feature-9999', 'job:App\\Jobs\\SendReceipt']);
});

it('runs a duplicate uuid only once, dispatched from two separate job instances', function (): void {
    Process::fake();

    $jobA = makeEnvironmentJob(['uuid' => 'shared-uuid']);
    $jobB = makeEnvironmentJob(['uuid' => 'shared-uuid']);

    $jobA->handle(app(PhpBinaryResolver::class));
    $jobB->handle(app(PhpBinaryResolver::class));

    Process::assertRanTimes(fn (): bool => true, 1);
});
