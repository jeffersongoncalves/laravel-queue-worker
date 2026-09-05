<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Exceptions;

use RuntimeException;

class MissingQueueTokenException extends RuntimeException
{
    public static function becauseTokenIsMissing(): self
    {
        return new self(
            'The [queue-worker.token] config value is not set. Refusing to boot without an X-Laravel-Queue-Token to authenticate against. Set the QUEUE_WORKER_TOKEN environment variable.'
        );
    }
}
