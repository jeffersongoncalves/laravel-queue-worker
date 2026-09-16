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
        expect($job->childTimeout)->toBe(900);

        return true;
    });
});

it('dispatches onto the queue posted by the environment when no override is configured', function (): void {
    Bus::fake();

    $payload = PayloadFactory::make();

    $this->postJson('/api/jobs', validJobRequest(['queue' => 'emails', 'payload' => $payload]), [
        'X-Laravel-Queue-Token' => 'test-token',
    ])->assertStatus(202);

    Bus::assertDispatched(
        RunEnvironmentJob::class,
        fn (RunEnvironmentJob $job): bool => $job->queue === 'emails' && $job->payload === $payload,
    );
});

it('forces every job onto the configured queue, ignoring the posted queue name', function (): void {
    Bus::fake();
    config(['queue-worker.queue' => 'environments']);

    $payload = PayloadFactory::make();

    $this->postJson('/api/jobs', validJobRequest(['queue' => 'emails', 'payload' => $payload]), [
        'X-Laravel-Queue-Token' => 'test-token',
    ])->assertStatus(202);

    Bus::assertDispatched(
        RunEnvironmentJob::class,
        fn (RunEnvironmentJob $job): bool => $job->queue === 'environments' && $job->payload === $payload,
    );
});

it('carries the posted queue name on the job even when the hub-side queue is overridden', function (): void {
    Bus::fake();
    config(['queue-worker.queue' => 'environments']);

    $this->postJson('/api/jobs', validJobRequest(['queue' => 'emails']), [
        'X-Laravel-Queue-Token' => 'test-token',
    ])->assertStatus(202);

    Bus::assertDispatched(
        RunEnvironmentJob::class,
        fn (RunEnvironmentJob $job): bool => $job->queue === 'environments' && $job->originalQueue === 'emails',
    );
});

it('falls back to the default queue name when the request omits it', function (): void {
    Bus::fake();

    $request = validJobRequest();
    unset($request['queue']);

    $this->postJson('/api/jobs', $request, ['X-Laravel-Queue-Token' => 'test-token'])->assertStatus(202);

    Bus::assertDispatched(
        RunEnvironmentJob::class,
        fn (RunEnvironmentJob $job): bool => $job->originalQueue === 'default',
    );
});

it('rejects a request pointing at the hub own directory and never dispatches a job', function (): void {
    Bus::fake();
    // Hub deployed inside allowed_root: the container binding is what feeds
    // base_path() to the validator, so resolve it through the app.
    $this->app->setBasePath(queueWorkerEnvironmentPath());

    $response = $this->postJson('/api/jobs', validJobRequest(), [
        'X-Laravel-Queue-Token' => 'test-token',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('path');

    Bus::assertNothingDispatched();
});

it('rejects a payload declaring no timeout instead of running it for one second', function (): void {
    Bus::fake();

    $response = $this->postJson('/api/jobs', validJobRequest([
        'payload' => PayloadFactory::make(['timeout' => 0]),
    ]), ['X-Laravel-Queue-Token' => 'test-token']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('payload');

    Bus::assertNothingDispatched();
});

it('rejects a negative payload timeout, which Symfony Process refuses outright', function (): void {
    Bus::fake();

    $response = $this->postJson('/api/jobs', validJobRequest([
        'payload' => PayloadFactory::make(['timeout' => -30]),
    ]), ['X-Laravel-Queue-Token' => 'test-token']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('payload');

    Bus::assertNothingDispatched();
});

it('still accepts a payload that omits the timeout, falling back to the default', function (): void {
    Bus::fake();

    $payload = PayloadFactory::make();
    $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    unset($decoded['timeout']);

    $this->postJson('/api/jobs', validJobRequest([
        'payload' => json_encode($decoded, JSON_THROW_ON_ERROR),
    ]), ['X-Laravel-Queue-Token' => 'test-token'])->assertStatus(202);

    Bus::assertDispatched(fn (RunEnvironmentJob $job): bool => $job->childTimeout === 60);
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
        fn (RunEnvironmentJob $job): bool => $job->uuid === 'job-a' && $job->tries === 2 && $job->childTimeout === 300,
    );
    Bus::assertDispatched(
        RunEnvironmentJob::class,
        fn (RunEnvironmentJob $job): bool => $job->uuid === 'job-b' && $job->tries === 7 && $job->childTimeout === 3600,
    );
});

it('rejects a plain HTTP request with 426 before looking at the token', function (): void {
    config(['queue-worker.require_https' => true]);

    Bus::fake();

    $response = $this->postJson('http://localhost/api/jobs', validJobRequest(), [
        'X-Laravel-Queue-Token' => 'test-token',
    ]);

    $response->assertStatus(426);
    Bus::assertNothingDispatched();
});

it('accepts an HTTPS request while require_https is on', function (): void {
    config(['queue-worker.require_https' => true]);

    Bus::fake();

    $this->postJson('https://localhost/api/jobs', validJobRequest(), [
        'X-Laravel-Queue-Token' => 'test-token',
    ])->assertStatus(202);

    Bus::assertDispatched(RunEnvironmentJob::class);
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
