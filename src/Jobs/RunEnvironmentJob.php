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

    /**
     * How much longer this job may live than the child it supervises. Both
     * numbers come from the same payload field, so without a margin they
     * expire together and it is a coin toss which fires first. The child
     * timing out first is the path worth having: Symfony kills it and the
     * failed_jobs row names the environment. The other way round, the
     * worker's pcntl_alarm kills this job mid-run, leaving an orphan child.
     *
     * Public because the controller has to know what a posted timeout grows
     * into before it dispatches anything: that total, not the posted value,
     * is what has to stay under the connection's retry_after.
     */
    public const TIMEOUT_MARGIN = 60;

    public int $tries;

    public int $timeout;

    /**
     * The timeout the environment declared on its own job, applied to the
     * child process. The constructor always sets it above zero, so the zero
     * default marks a job serialized before this property existed — those
     * still unserialize, and childProcessTimeout() derives their child
     * timeout from $timeout instead.
     */
    public int $childTimeout = 0;

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
        $this->childTimeout = max(1, $timeout);
        $this->timeout = $this->childTimeout + self::TIMEOUT_MARGIN;
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

        $result = Process::path($this->path)
            ->timeout($this->childProcessTimeout())
            ->env($this->scrubbedEnvironment())
            ->run([
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
     * How long the child may run. Normally $childTimeout, which the
     * constructor set from the payload and which $timeout already exceeds by
     * TIMEOUT_MARGIN.
     *
     * A job queued before $childTimeout existed carries no value for it, and
     * the worker's pcntl_alarm reads the timeout off the *outer* queue payload
     * written at dispatch — which nothing here can rewrite, since that payload
     * is already in Redis. What it can do is take the margin out of the child's
     * share instead, so the child still dies first. Below the margin there is
     * not a whole margin to take, and one second is reserved instead — a
     * second off the child's budget buys the same ordering. At a one-second
     * timeout even that is gone, since no smaller positive value exists, and
     * the two expire together exactly as they did before the upgrade.
     */
    private function childProcessTimeout(): int
    {
        if ($this->childTimeout > 0) {
            return $this->childTimeout;
        }

        $reserved = $this->timeout > self::TIMEOUT_MARGIN ? self::TIMEOUT_MARGIN : 1;

        return max(1, $this->timeout - $reserved);
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
