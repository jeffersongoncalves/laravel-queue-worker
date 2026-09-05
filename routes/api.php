<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JeffersonGoncalves\QueueWorker\Http\Controllers\JobController;
use JeffersonGoncalves\QueueWorker\Http\Middleware\VerifyQueueWorkerToken;

Route::prefix((string) config('queue-worker.route_prefix', 'api'))
    ->middleware(config('queue-worker.route_middleware', ['api']))
    ->group(function (): void {
        Route::post('/jobs', JobController::class)
            ->middleware(VerifyQueueWorkerToken::class)
            ->name('queue-worker.jobs.store');
    });
