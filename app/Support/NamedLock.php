<?php

namespace App\Support;

use Closure;
use RuntimeException;

/**
 * MySQL GET_LOCK wrapper — the `db_lock()` the legacy backends put around
 * whole-state saves (kompas_save, mkt_save, konten_save, tix, …).
 *
 * The lock name is prefixed with the DATABASE name, exactly like the legacy
 * `GET_LOCK('<DB_NAME>:<name>')`, so while the old PHP backend and this API
 * run side by side against the same database they serialise against each
 * other — not just against themselves.
 */
final class NamedLock
{
    public static function run(string $module, string $name, Closure $fn, int $timeoutSeconds = 10): mixed
    {
        $db = Modules::db($module);
        $lock = Modules::databaseName($module).':'.$name;

        $got = (int) ($db->selectOne('SELECT GET_LOCK(?, ?) AS l', [$lock, $timeoutSeconds])->l ?? 0);
        if ($got !== 1) {
            throw new RuntimeException('server sibuk, coba lagi');
        }
        try {
            return $fn();
        } finally {
            $db->select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
