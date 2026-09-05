<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker;

use Illuminate\Filesystem\Filesystem;
use JeffersonGoncalves\QueueWorker\Exceptions\UnresolvedPhpBinaryException;
use JsonException;

class PhpBinaryResolver
{
    /** @var array<string, string> */
    private array $resolved = [];

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * Resolve the PHP binary configured for the given environment's own
     * composer.json "require.php" constraint, caching the result per path
     * so composer.json is never re-read for a path already resolved.
     *
     * @throws UnresolvedPhpBinaryException
     * @throws JsonException
     */
    public function resolve(string $path): string
    {
        if (isset($this->resolved[$path])) {
            return $this->resolved[$path];
        }

        $composerJsonPath = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'composer.json';

        if (! $this->files->exists($composerJsonPath)) {
            throw new UnresolvedPhpBinaryException("No composer.json found at [{$composerJsonPath}].");
        }

        /** @var array<string, mixed> $composer */
        $composer = json_decode($this->files->get($composerJsonPath), true, flags: JSON_THROW_ON_ERROR);

        $constraint = (string) data_get($composer, 'require.php', '');

        $version = $this->extractVersion($constraint);

        /** @var array<string, string> $map */
        $map = config('queue-worker.php_binary_map', []);

        if ($version === null || ! isset($map[$version])) {
            throw new UnresolvedPhpBinaryException(
                "No PHP binary configured for constraint [{$constraint}] declared in [{$composerJsonPath}]."
            );
        }

        return $this->resolved[$path] = $map[$version];
    }

    private function extractVersion(string $constraint): ?string
    {
        return preg_match('/(\d+\.\d+)/', $constraint, $matches) === 1 ? $matches[1] : null;
    }
}
