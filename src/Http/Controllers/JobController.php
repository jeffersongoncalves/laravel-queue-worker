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

        $queue = $data['queue'] ?? 'default';

        RunEnvironmentJob::dispatch(
            slug: $data['slug'],
            path: $data['path'],
            payload: $data['payload'],
            uuid: $metadata->uuid,
            displayName: $metadata->displayName,
            maxTries: $metadata->maxTries,
            timeout: $metadata->timeout,
        )->onQueue($queue)->delay((int) ($data['delay'] ?? 0));

        return response()->json(['id' => $metadata->uuid], 202);
    }
}
