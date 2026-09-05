<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use JeffersonGoncalves\QueueWorker\Exceptions\MissingQueueTokenException;
use JeffersonGoncalves\QueueWorker\Jobs\RunEnvironmentJob;
use JeffersonGoncalves\QueueWorker\Tests\Fixtures\PayloadFactory;

function queueWorkerEnvironmentPath(): string
{
    return __DIR__.'/../Fixtures/environments/app-feature-1234';
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validJobRequest(array $overrides = []): array
{
    return array_merge([
        'slug' => 'app-feature-1234',
        'path' => queueWorkerEnvironmentPath(),
        'queue' => 'default',
        'delay' => 0,
        'payload' => PayloadFactory::make(),
    ], $overrides);
}

it('accepts a valid request, queues the job, and responds 202 with the hub job id', function (): void {
    Bus::fake();

    $payload = PayloadFactory::make(['maxTries' => 5, 'timeout' => 900]);
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

    $response = $this->postJson('/api/jobs', validJobRequest(['payload' => $payload]), [
        'X-Laravel-Queue-Token' => 'test-token',
    ]);

    $response->assertStatus(202);
    $response->assertExactJson(['id' => $decoded['uuid']]);

    Bus::assertDispatched(RunEnvironmentJob::class, function (RunEnvironmentJob $job) use ($decoded): bool {
        expect($job->slug)->toBe('app-feature-1234');
        expect($job->uuid)->toBe($decoded['uuid']);
        expect($job->displayName)->toBe($decoded['displayName']);
        expect($job->tries)->toBe(5);
        expect($job->timeout)->toBe(900);

        return true;
    });
});

it('derives tries and timeout per request instead of a hardcoded constant', function (): void {
    Bus::fake();

    $this->postJson('/api/jobs', validJobRequest([
        'payload' => PayloadFactory::make(['uuid' => 'job-a', 'maxTries' => 2, 'timeout' => 300]),
    ]), ['X-Laravel-Queue-Token' => 'test-token'])->assertStatus(202);

    $this->postJson('/api/jobs', validJobRequest([
        'payload' => PayloadFactory::make(['uuid' => 'job-b', 'maxTries' => 7, 'timeout' => 3600]),
    ]), ['X-Laravel-Queue-Token' => 'test-token'])->assertStatus(202);

    Bus::assertDispatched(
        RunEnvironmentJob::class,
        fn (RunEnvironmentJob $job): bool => $job->uuid === 'job-a' && $job->tries === 2 && $job->timeout === 300,
    );
    Bus::assertDispatched(
        RunEnvironmentJob::class,
        fn (RunEnvironmentJob $job): bool => $job->uuid === 'job-b' && $job->tries === 7 && $job->timeout === 3600,
    );
});

it('rejects a request without the token header and processes nothing', function (): void {
    Bus::fake();

    $response = $this->postJson('/api/jobs', validJobRequest());

    $response->assertStatus(403);
    Bus::assertNothingDispatched();
});

it('rejects a request with the wrong token and processes nothing', function (): void {
    Bus::fake();

    $response = $this->postJson('/api/jobs', validJobRequest(), [
        'X-Laravel-Queue-Token' => 'wrong-token',
    ]);

    $response->assertStatus(403);
    Bus::assertNothingDispatched();
});

it('rejects every request when no token is configured at all, regardless of headers', function (): void {
    config(['queue-worker.token' => null]);

    Bus::fake();
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/api/jobs', validJobRequest(), [
        'X-Laravel-Queue-Token' => 'anything',
    ]))->toThrow(MissingQueueTokenException::class);

    Bus::assertNothingDispatched();
});

it('rejects a path outside the allowed root and never starts a process', function (): void {
    Bus::fake();
    Process::fake();

    $response = $this->postJson('/api/jobs', validJobRequest([
        'slug' => 'tmp',
        'path' => sys_get_temp_dir(),
    ]), ['X-Laravel-Queue-Token' => 'test-token']);

    $response->assertStatus(422);
    Bus::assertNothingDispatched();
    Process::assertNothingRan();
});

it('rejects a path traversal attempt and never starts a process', function (): void {
    Bus::fake();
    Process::fake();

    $response = $this->postJson('/api/jobs', validJobRequest([
        'path' => queueWorkerEnvironmentPath().'/../../../../../',
    ]), ['X-Laravel-Queue-Token' => 'test-token']);

    $response->assertStatus(422);
    Bus::assertNothingDispatched();
    Process::assertNothingRan();
});

it('rejects a path with no artisan file and never starts a process', function (): void {
    Bus::fake();
    Process::fake();

    $response = $this->postJson('/api/jobs', validJobRequest([
        'slug' => 'environments',
        'path' => __DIR__.'/../Fixtures/environments',
    ]), ['X-Laravel-Queue-Token' => 'test-token']);

    $response->assertStatus(422);
    Bus::assertNothingDispatched();
    Process::assertNothingRan();
});

it('rejects a path whose basename does not match the slug', function (): void {
    Bus::fake();
    Process::fake();

    $response = $this->postJson('/api/jobs', validJobRequest([
        'slug' => 'someone-elses-slug',
    ]), ['X-Laravel-Queue-Token' => 'test-token']);

    $response->assertStatus(422);
    Bus::assertNothingDispatched();
    Process::assertNothingRan();
});

it('rejects every request when allowed_root is not configured, regardless of path validity', function (): void {
    config(['queue-worker.allowed_root' => null]);

    Bus::fake();
    Process::fake();

    $response = $this->postJson('/api/jobs', validJobRequest(), [
        'X-Laravel-Queue-Token' => 'test-token',
    ]);

    $response->assertStatus(422);
    Bus::assertNothingDispatched();
    Process::assertNothingRan();
});

it('runs only one job execution for a duplicate uuid POSTed twice', function (): void {
    config([
        'queue.default' => 'sync',
        'queue-worker.php_binary_map' => ['8.2' => 'php'],
    ]);

    Process::fake();

    $payload = PayloadFactory::make(['uuid' => 'duplicate-uuid']);

    $this->postJson('/api/jobs', validJobRequest(['payload' => $payload]), [
        'X-Laravel-Queue-Token' => 'test-token',
    ])->assertStatus(202);

    $this->postJson('/api/jobs', validJobRequest(['payload' => $payload]), [
        'X-Laravel-Queue-Token' => 'test-token',
    ])->assertStatus(202);

    Process::assertRanTimes(fn (): bool => true, 1);
});
