<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Support;

use JsonException;

/**
 * Reads only the outer bookkeeping fields off a Laravel job payload string.
 * The "data.command" field (the actual serialized job) is never touched.
 */
final readonly class JobMetadata
{
    private const DEFAULT_MAX_TRIES = 1;

    private const DEFAULT_TIMEOUT = 60;

    public function __construct(
        public string $uuid,
        public string $displayName,
        public int $maxTries,
        public int $timeout,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromPayload(string $payload): self
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return new self(
            uuid: (string) ($decoded['uuid'] ?? ''),
            displayName: (string) ($decoded['displayName'] ?? 'unknown'),
            maxTries: (int) ($decoded['maxTries'] ?? self::DEFAULT_MAX_TRIES),
            timeout: (int) ($decoded['timeout'] ?? self::DEFAULT_TIMEOUT),
        );
    }
}
