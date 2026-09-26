<?php

namespace Tests;

use App\Auth\AccountRepository;
use App\Modules\Absensi\Services\AbsensiService;
use App\Modules\Akademi\Services\AkademiSchema;
use App\Modules\Bd\Services\BdState;
use App\Modules\Event\Services\EventState;
use App\Modules\Finance\Services\KasKecil;
use App\Modules\Hlife\Services\HlifeState;
use App\Modules\Hr\Services\HrState;
use App\Modules\Jadwal\Services\JadwalService;
use App\Modules\Kompas\Services\KompasState;
use App\Modules\Konten\Services\KontenSchema;
use App\Modules\Marketing\Services\MarketingSchema;
use App\Modules\Reservasi\Services\ReservasiState;
use App\Modules\Ticketing\Services\TicketSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

/**
 * Feature tests run against the LOCAL restored legacy databases
 * (tools/restore-dumps.sh) plus a dedicated `lakk5493_laksamana_core_test`.
 *
 * Every test is wrapped in a transaction on EVERY connection, so tests may
 * write freely and nothing persists. Migrations run only on `core`.
 * Never point these connections at a live server.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Transact all connections: core + every legacy DB. */
    protected function connectionsToTransact(): array
    {
        $names = ['core'];
        foreach (array_keys(config('database.connections')) as $name) {
            if (str_starts_with($name, 'legacy_')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * With identity on core (DB_ACCOUNT_CONNECTION=core, #44) the freshly
     * migrated test core is filled from the restored legacy DBs once per run;
     * with jadwal on core (#47) its tables follow, after the account import
     * the user FKs point at. Marketing (#49) has no user FKs and imports
     * straight from its own legacy database; absensi (#53) likewise, while
     * its shift lookups follow whatever connection jadwal/dw use.
     */
    protected function afterRefreshingDatabase(): void
    {
        if (AccountRepository::onCore()) {
            Artisan::call('core:import', ['module' => 'account']);
        }
        if (JadwalService::onCore()) {
            Artisan::call('core:import', ['module' => 'jadwal']);
        }
        if (MarketingSchema::onCore()) {
            Artisan::call('core:import', ['module' => 'marketing']);
        }
        if (AbsensiService::onCore()) {
            Artisan::call('core:import', ['module' => 'absensi']);
        }
        if (KontenSchema::onCore()) {
            Artisan::call('core:import', ['module' => 'konten']);
        }
        if (AkademiSchema::onCore()) {
            Artisan::call('core:import', ['module' => 'akademi']);
        }
        if (BdState::onCore()) {
            Artisan::call('core:import', ['module' => 'bd']);
        }
        if (HrState::onCore()) {
            Artisan::call('core:import', ['module' => 'hr']);
        }
        if (HlifeState::onCore()) {
            Artisan::call('core:import', ['module' => 'hlife']);
        }
        // finance (#67) reads accounts for the '#<user id>' role keys
        if (KasKecil::onCore()) {
            Artisan::call('core:import', ['module' => 'finance']);
        }
        if (EventState::onCore()) {
            Artisan::call('core:import', ['module' => 'event']);
        }
        if (TicketSchema::onCore()) {
            Artisan::call('core:import', ['module' => 'ticketing']);
        }
        if (KompasState::onCore()) {
            Artisan::call('core:import', ['module' => 'kompas']);
        }
        if (ReservasiState::onCore()) {
            Artisan::call('core:import', ['module' => 'reservasi']);
        }
    }

    /** POST a legacy text/plain JSON body to an old-style URL. */
    protected function legacyPost(string $uri, array $body): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
    }
}
