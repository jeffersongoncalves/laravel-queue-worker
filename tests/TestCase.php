<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker\Tests;

use JeffersonGoncalves\QueueWorker\QueueWorkerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            QueueWorkerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');

        $app['config']->set('queue-worker.token', 'test-token');
        $app['config']->set('queue-worker.allowed_root', __DIR__.'/Fixtures/environments');
    }
}
