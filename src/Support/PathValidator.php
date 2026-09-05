<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Support;

use JeffersonGoncalves\QueueWorker\Exceptions\InvalidEnvironmentPathException;

class PathValidator
{
    public function __construct(
        private readonly ?string $allowedRoot,
    ) {}

    /**
     * Resolve an untrusted environment path, guaranteeing it lives inside the
     * configured allowed root, contains an "artisan" file, and that its
     * basename matches the given slug.
     *
     * @throws InvalidEnvironmentPathException
     */
    public function resolve(string $path, string $slug): string
    {
        if ($this->allowedRoot === null || $this->allowedRoot === '') {
            throw new InvalidEnvironmentPathException(
                'The [queue-worker.allowed_root] config value is not set. Refusing every path.'
            );
        }

        $realAllowedRoot = realpath($this->allowedRoot);

        if ($realAllowedRoot === false) {
            throw new InvalidEnvironmentPathException(
                "The configured allowed_root [{$this->allowedRoot}] does not exist."
            );
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            throw new InvalidEnvironmentPathException("Unable to resolve path [{$path}].");
        }

        $normalizedRoot = rtrim($realAllowedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($realPath.DIRECTORY_SEPARATOR, $normalizedRoot)) {
            throw new InvalidEnvironmentPathException("Path [{$path}] is outside the allowed root.");
        }

        if (! is_file($realPath.DIRECTORY_SEPARATOR.'artisan')) {
            throw new InvalidEnvironmentPathException("No artisan file found in [{$path}].");
        }

        if (basename($realPath) !== $slug) {
            throw new InvalidEnvironmentPathException("Path [{$path}] does not belong to slug [{$slug}].");
        }

        return $realPath;
    }
}
