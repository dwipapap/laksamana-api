<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\CoreImportCommand;
use App\Core\Imports\AbsensiImporter;
use App\Core\Imports\AccountImporter;
use App\Core\Imports\AkademiImporter;
use App\Core\Imports\BdImporter;
use App\Core\Imports\DummyImporter;
use App\Core\Imports\EventImporter;
use App\Core\Imports\HrImporter;
use App\Core\Imports\ImporterRegistry;
use App\Core\Imports\JadwalImporter;
use App\Core\Imports\KontenImporter;
use App\Core\Imports\MarketingImporter;
use App\Core\Imports\TicketingImporter;
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
            // bd_people.user_id needs the account import first (--list is alphabetical, so it is)
            $app->make(BdImporter::class),
            // EMS: event owns the tables the public shop also uses, so event imports before ticketing
            $app->make(EventImporter::class),
            $app->make(HrImporter::class),
            $app->make(JadwalImporter::class),
            $app->make(KontenImporter::class),
            $app->make(MarketingImporter::class),
            $app->make(TicketingImporter::class),
        ));
    }

    public function boot(): void
    {
        $this->commands([CoreImportCommand::class]);
    }
}
