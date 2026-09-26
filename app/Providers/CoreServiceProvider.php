<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\CoreImportCommand;
use App\Core\Imports\AbsensiImporter;
use App\Core\Imports\AccountImporter;
use App\Core\Imports\AkademiImporter;
use App\Core\Imports\DummyImporter;
use App\Core\Imports\ImporterRegistry;
use App\Core\Imports\JadwalImporter;
use App\Core\Imports\KontenImporter;
use App\Core\Imports\MarketingImporter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImporterRegistry::class, fn (Application $app) => new ImporterRegistry(
            $app->make(DummyImporter::class),
            $app->make(AbsensiImporter::class),
            $app->make(AkademiImporter::class),
            $app->make(AccountImporter::class),
            $app->make(JadwalImporter::class),
            $app->make(KontenImporter::class),
            $app->make(MarketingImporter::class),
        ));
    }

    public function boot(): void
    {
        $this->commands([CoreImportCommand::class]);
    }
}
