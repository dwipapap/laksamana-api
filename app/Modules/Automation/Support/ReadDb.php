<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single door to the Automation read-only database (#234).
 *
 * `ReadDb::for('marketing')` returns a connection to the module's database:
 *
 *  - AUTOMATION_DB_HOST empty  -> the module connection itself (local & tests,
 *    where there is no prod database to pin).
 *  - AUTOMATION_DB_HOST filled -> the `automation_ro` connection with its
 *    database swapped to config('laksamana.modules.<module>.database'), so
 *    every feed shares one SELECT-only user and one set of credentials.
 *
 * Only this class names that connection; the arch test in
 * tests/Feature/Automation keeps it that way. Feeds read config() through
 * here and never call env() themselves.
 */
final class ReadDb
{
    public static function for(string $module): ConnectionInterface
    {
        $modul = config("laksamana.modules.{$module}");
        if (! is_array($modul)) {
            throw new InvalidArgumentException("Unknown laksamana module [{$module}].");
        }
        $database = (string) ($modul['database'] ?? '');
        if ($database === '') {
            throw new InvalidArgumentException("Module [{$module}] has no database configured.");
        }

        // Not pinned (local/tests): read exactly like the module itself does.
        if ((string) config('database.connections.automation_ro.host', '') === '') {
            return Modules::db($module);
        }

        // Pinned: one connection, the database swapped per module. The purge
        // makes the next query open the PDO on the swapped database.
        config(['database.connections.automation_ro.database' => $database]);
        DB::purge('automation_ro');

        return DB::connection('automation_ro');
    }
}
