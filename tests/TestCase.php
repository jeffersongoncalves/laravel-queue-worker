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
        $app['config']->set('database.connections.testing', $this->testing_connection());

        $app['config']->set('cache.default', 'array');

        $app['config']->set('queue-worker.token', 'test-token');
        // The test requests are plain HTTP; the guard has its own tests.
        $app['config']->set('queue-worker.require_https', false);
        $app['config']->set('queue-worker.allowed_root', __DIR__.'/Fixtures/environments');
    }

    /**
     * The original in-memory SQLite connection by default; CI (tests.yml) sets
     * QUEUE_WORKER_TEST_DB_* to run the same suite on MySQL and PostgreSQL. Not DB_CONNECTION:
     * Testbench pins it to "testing", which would always win over a driver read from it.
     *
     * @return array<string, mixed>
     */
    protected function testing_connection(): array
    {
        $driver = env('QUEUE_WORKER_TEST_DB_DRIVER', 'sqlite');

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => $driver,
            'host' => env('QUEUE_WORKER_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('QUEUE_WORKER_TEST_DB_PORT'),
            'database' => env('QUEUE_WORKER_TEST_DB_DATABASE', 'testing'),
            'username' => env('QUEUE_WORKER_TEST_DB_USERNAME', 'root'),
            'password' => env('QUEUE_WORKER_TEST_DB_PASSWORD', ''),
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'prefix' => '',
        ];
    }
}
