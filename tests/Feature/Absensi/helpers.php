<?php

use App\Modules\Absensi\Services\AbsensiService;
use Illuminate\Support\Str;

/**
 * An absensi SQL statement in legacy names, run on whichever storage the Modul
 * is on. The `abs_*` tables keep their names on core (#53), but the legacy row
 * id moves to `legacy_id` (a fresh ULID becomes the PK) and reads alias it back
 * to `id` — the same swap AbsensiService makes with `idCol()` / `idSelect()`.
 * See docs/db/absensi.md for the full mapping.
 */
function absSql(string $sql): string
{
    if (! AbsensiService::onCore()) {
        return $sql;
    }

    if (preg_match('/^\s*INSERT INTO/i', $sql)) {
        // (`id`,`cols`) VALUES ('legacy', ...) -> (`id`,`legacy_id`,`cols`) VALUES (<ulid>, 'legacy', ...)
        $sql = preg_replace('/\((`?)id\1,/', '(${1}id${1}, `legacy_id`,', $sql, 1);

        return preg_replace('/VALUES\s*\(/', "VALUES ('".strtolower((string) Str::ulid())."',", $sql, 1);
    }

    $sql = preg_replace('/\bSELECT\s+(`?)id\b(`?)/', 'SELECT ${1}legacy_id${2} AS id', $sql);

    return preg_replace('/\bWHERE\s+(`?)id\b(`?)/', 'WHERE ${1}legacy_id${2}', $sql);
}
