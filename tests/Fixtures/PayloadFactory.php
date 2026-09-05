<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Tests\Fixtures;

use Illuminate\Support\Str;

class PayloadFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function make(array $overrides = []): string
    {
        return json_encode(array_merge([
            'uuid' => (string) Str::uuid(),
            'displayName' => 'App\\Jobs\\GenerateInvoice',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 3,
            'timeout' => 1800,
            'backoff' => null,
            'retryUntil' => null,
            'data' => [
                'commandName' => 'App\\Jobs\\GenerateInvoice',
                'command' => 'O:42:"App\\Jobs\\GenerateInvoice":0:{}',
            ],
        ], $overrides), JSON_THROW_ON_ERROR);
    }
}
