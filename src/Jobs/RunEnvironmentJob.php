<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JeffersonGoncalves\QueueWorker\Exceptions\EnvironmentProcessFailedException;
use JeffersonGoncalves\QueueWorker\PhpBinaryResolver;

class RunEnvironmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly string $slug,
        public readonly string $path,
        public readonly string $payload,
        public readonly string $uuid,
        public readonly string $displayName,
        int $maxTries,
        int $timeout,
    ) {
        $this->tries = max(1, $maxTries);
        $this->timeout = max(1, $timeout);
    }

    public function handle(PhpBinaryResolver $phpBinaryResolver): void
    {
        // ponytail: the dedup guard only applies to a job's *first* attempt.
        // Horizon retries reuse this same job instance with attempts() > 1,
        // and must always be allowed to run again.
        if ($this->attempts() <= 1 && ! $this->markAsSeen()) {
            return;
        }

        if (! is_dir($this->path)) {
            Log::debug("Environment [{$this->slug}] at [{$this->path}] no longer exists; discarding job.");
            $this->delete();

            return;
        }

        $phpBinary = $phpBinaryResolver->resolve($this->path);

        $result = Process::path($this->path)->run([
            $phpBinary,
            'artisan',
            'queue-consumer:run',
            '--payload='.base64_encode($this->payload),
            ...($this->attempts() >= $this->tries ? ['--last-attempt'] : []),
        ]);

        if ($result->failed()) {
            throw new EnvironmentProcessFailedException(
                "Child process for environment [{$this->slug}] exited with code [{$result->exitCode()}]: {$result->errorOutput()}"
            );
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["env:{$this->slug}", "job:{$this->displayName}"];
    }

    private function markAsSeen(): bool
    {
        return Cache::add(
            "queue-worker:seen:{$this->uuid}",
            true,
            now()->addHours((int) config('queue-worker.dedup_window_hours', 24)),
        );
    }
}
