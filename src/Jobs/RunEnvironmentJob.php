<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Jobs;

use Dotenv\Dotenv;
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

    /**
     * The queue the environment posted, which is what a released job must
     * return to. Distinct from $queue, the hub-side queue this job runs on:
     * with queue-worker.queue set the two differ on purpose.
     *
     * It carries a default so a job serialized by an older version of this
     * package still unserializes after an upgrade.
     */
    public string $originalQueue = 'default';

    public function __construct(
        public readonly string $slug,
        public readonly string $path,
        public readonly string $payload,
        public readonly string $uuid,
        public readonly string $displayName,
        int $maxTries,
        int $timeout,
        string $originalQueue = 'default',
    ) {
        $this->tries = max(1, $maxTries);
        $this->timeout = max(1, $timeout);
        $this->originalQueue = $originalQueue;
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

        $result = Process::path($this->path)->env($this->scrubbedEnvironment())->run([
            $phpBinary,
            'artisan',
            'queue-consumer:run',
            '--payload='.base64_encode($this->payload),
            '--queue='.$this->originalQueue,
            ...($this->attempts() >= $this->tries ? ['--last-attempt'] : []),
        ]);

        if ($result->failed()) {
            // Laravel renders the child's exception through its console handler,
            // which writes to stdout, so errorOutput() alone is often empty.
            $details = $this->truncate(trim($result->errorOutput()."\n".$result->output()));

            throw new EnvironmentProcessFailedException(
                "Child process for environment [{$this->slug}] exited with code [{$result->exitCode()}]: {$details}"
            );
        }
    }

    /**
     * A whole stack trace has no business in a failed_jobs row. Keep both
     * ends: the head carries the rendered exception (class, message, file),
     * the tail carries whatever the process died on last.
     */
    private function truncate(string $output): string
    {
        $limit = 4000;
        $marker = "\n[... truncated ...]\n";

        if (mb_strlen($output) <= $limit) {
            return $output;
        }

        $half = intdiv($limit - mb_strlen($marker), 2);

        return mb_substr($output, 0, $half).$marker.mb_substr($output, -$half);
    }

    /**
     * Symfony inherits this process's environment, and Laravel has published
     * the hub's own .env into it. The child bootstraps with
     * Dotenv::createImmutable, which never overwrites what is already set, so
     * every key the hub defines would silently beat the environment's own
     * .env — wrong database host, nested dispatches landing in the hub's
     * Redis, the hub's APP_KEY. Passing each of those keys as false removes
     * it from the child environment, letting the child's own Dotenv win. The
     * rest of the shell environment is left untouched.
     *
     * @return array<string, false>
     */
    private function scrubbedEnvironment(): array
    {
        $keys = [
            ...array_keys(Dotenv::createArrayBacked(base_path())->safeLoad()),
            ...array_keys(Dotenv::createArrayBacked($this->path)->safeLoad()),
        ];

        // PATH is the child's way of finding anything it shells out to, and
        // Windows matches environment names case-insensitively, so a "Path"
        // key in either file would remove it just the same.
        $keys = array_filter(
            array_unique($keys),
            fn (string $key): bool => strcasecmp($key, 'PATH') !== 0,
        );

        return array_fill_keys($keys, false);
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
