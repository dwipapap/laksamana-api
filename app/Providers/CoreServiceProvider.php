<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\CoreImportCommand;
use App\Console\Commands\OfficeGrantCommand;
use App\Core\Imports\AbsensiImporter;
use App\Core\Imports\AccountImporter;
use App\Core\Imports\AkademiImporter;
use App\Core\Imports\BdImporter;
use App\Core\Imports\DummyImporter;
use App\Core\Imports\DwImporter;
use App\Core\Imports\EventImporter;
use App\Core\Imports\FinanceImporter;
use App\Core\Imports\HlifeImporter;
use App\Core\Imports\HrImporter;
use App\Core\Imports\ImporterRegistry;
use App\Core\Imports\JadwalImporter;
use App\Core\Imports\KompasImporter;
use App\Core\Imports\KontenImporter;
use App\Core\Imports\MarketingImporter;
use App\Core\Imports\ReservasiImporter;
use App\Core\Imports\StockImporter;
use App\Core\Imports\TicketingImporter;
use App\Erp\Master\Imports\BarangImporter;
use App\Erp\Master\Imports\OrangImporter;
use App\Erp\Persediaan\Imports\PersediaanImporter;
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
            // finance_kk_peran.user_id is the '#<Office User id>' key resolved, so after account
            $app->make(FinanceImporter::class),
            $app->make(HrImporter::class),
            // hlife has no user FKs and imports straight from its own legacy database
            $app->make(HlifeImporter::class),
            $app->make(JadwalImporter::class),
            // kompas_an_akses/an_peran.user_id need the account import first
            $app->make(KompasImporter::class),
            $app->make(KontenImporter::class),
            $app->make(MarketingImporter::class),
            $app->make(DwImporter::class),
            $app->make(ReservasiImporter::class),
            // stock has no user FKs and imports straight from its own legacy database
            $app->make(StockImporter::class),
            $app->make(TicketingImporter::class),
            $app->make(BarangImporter::class),
            $app->make(PersediaanImporter::class),
            // ERP people and Divisi: after `account` (Users, shift Divisi)
            $app->make(OrangImporter::class),
        ));
    }

    public function boot(): void
    {
        $this->commands([CoreImportCommand::class, OfficeGrantCommand::class]);
    }
}
