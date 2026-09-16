<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JeffersonGoncalves\QueueWorker\Exceptions\InvalidEnvironmentPathException;
use JeffersonGoncalves\QueueWorker\Jobs\RunEnvironmentJob;
use JeffersonGoncalves\QueueWorker\Support\JobMetadata;
use JeffersonGoncalves\QueueWorker\Support\PathValidator;
use JsonException;

class JobController
{
    public function __construct(
        private readonly PathValidator $pathValidator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string'],
            'path' => ['required', 'string'],
            'queue' => ['nullable', 'string'],
            'delay' => ['nullable', 'integer', 'min:0'],
            'payload' => ['required', 'string'],
        ]);

        try {
            $this->pathValidator->resolve($data['path'], $data['slug']);
        } catch (InvalidEnvironmentPathException $exception) {
            throw ValidationException::withMessages(['path' => [$exception->getMessage()]]);
        }

        try {
            $metadata = JobMetadata::fromPayload($data['payload']);
        } catch (JsonException) {
            throw ValidationException::withMessages(['payload' => ['The payload is not valid JSON.']]);
        }

        // Laravel reads a zero timeout as "no timeout" (pcntl_alarm(0) cancels
        // the alarm), which the hub cannot honor: the child would hold a worker
        // indefinitely and retry_after would reclaim and duplicate the job while
        // it still runs. A negative one is invalid to Symfony Process outright.
        // Neither can be quietly clamped, since max(1, $timeout) would run a job
        // that declared no limit for one second, so both are rejected here.
        if ($metadata->timeout < 1) {
            throw ValidationException::withMessages(['payload' => [
                'The payload timeout must be at least 1 second. The hub supervises every job with a '
                .'process timeout, so it cannot run a job that declares no limit.',
            ]]);
        }

        $this->assertFitsWithinRetryAfter($metadata->timeout);

        $override = config('queue-worker.queue');

        // The posted name belongs to the originating application and travels
        // with the job so a release returns to it; the hub-side queue is the
        // operator's routing decision and may differ.
        $originalQueue = $data['queue'] ?? 'default';

        $queue = is_string($override) && $override !== ''
            ? $override
            : $originalQueue;

        RunEnvironmentJob::dispatch(
            slug: $data['slug'],
            path: $data['path'],
            payload: $data['payload'],
            uuid: $metadata->uuid,
            displayName: $metadata->displayName,
            maxTries: $metadata->maxTries,
            timeout: $metadata->timeout,
            originalQueue: $originalQueue,
        )->onQueue($queue)->delay((int) ($data['delay'] ?? 0));

        return response()->json(['id' => $metadata->uuid], 202);
    }

    /**
     * A queue connection hands a reserved job back to another worker once
     * retry_after seconds pass, without asking whether the first one is still
     * running. The hub job lives for the posted timeout plus the margin, so
     * anything at or above retry_after is delivered twice and the environment
     * runs the same job concurrently — at-least-once turning into
     * at-least-twice, silently, with no failed job to read afterwards.
     *
     * The bound is read off the connection rather than configured separately,
     * so there is one number to keep right instead of two that can drift. A
     * connection without retry_after (sync, sqs, which carries its own
     * visibility timeout) has nothing to compare against and is left alone.
     */
    private function assertFitsWithinRetryAfter(int $timeout): void
    {
        $connection = config('queue.default');
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        if (! is_numeric($retryAfter)) {
            return;
        }

        $jobTimeout = $timeout + RunEnvironmentJob::TIMEOUT_MARGIN;

        if ($jobTimeout < (int) $retryAfter) {
            return;
        }

        throw ValidationException::withMessages(['payload' => [
            "The payload timeout of {$timeout} seconds becomes a {$jobTimeout} second hub job, which the "
            ."[{$connection}] connection would reclaim and run a second time after {$retryAfter} seconds. "
            .'Raise retry_after on that connection above the longest timeout any environment declares.',
        ]]);
    }
}
