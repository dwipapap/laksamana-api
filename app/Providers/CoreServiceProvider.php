<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\CoreImportCommand;
use App\Core\Imports\DummyImporter;
use App\Core\Imports\ImporterRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImporterRegistry::class, fn (Application $app) => new ImporterRegistry(
            $app->make(DummyImporter::class),
        ));
    }

    public function boot(): void
    {
        $this->commands([CoreImportCommand::class]);
    }
}
