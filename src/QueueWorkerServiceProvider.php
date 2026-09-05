<?php

declare(strict_types=1);

namespace JeffersonGoncalves\QueueWorker;

use Illuminate\Filesystem\Filesystem;
use JeffersonGoncalves\QueueWorker\Support\PathValidator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class QueueWorkerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('queue-worker')
            ->hasConfigFile()
            ->hasRoute('api')
            ->hasMigration('add_slug_and_display_name_to_failed_jobs_table');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(
            PhpBinaryResolver::class,
            fn ($app): PhpBinaryResolver => new PhpBinaryResolver($app->make(Filesystem::class)),
        );

        $this->app->bind(
            PathValidator::class,
            fn (): PathValidator => new PathValidator(config('queue-worker.allowed_root')),
        );
    }
}
