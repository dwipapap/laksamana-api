<?php

use App\Modules\Dw\Services\DwService;
use Illuminate\Support\Str;

/**
 * A dw SQL statement in legacy names, run on whichever storage the Modul is on.
 * The table names already carry the dw_ prefix and survive core unchanged (#51),
 * and dw_id/permintaan_id keep legacy values (soft links, no FKs). Only the row
 * id moves: on core the legacy `id` lives in `legacy_id` (aliased back to `id`
 * on reads) and the PK is a fresh ULID — an insert gets both, mirroring the
 * importer's sync().
 */
function dwSql(string $sql): string
{
    if (! DwService::onCore()) {
        return $sql;
    }

    if (preg_match('/^\s*INSERT INTO/i', $sql)) {
        // (id, cols) VALUES ('AJx', ...) -> (id, legacy_id, cols) VALUES (<ulid>, 'AJx', ...)
        $sql = preg_replace('/\((id),/', '($1, legacy_id,', $sql);

        return preg_replace('/VALUES\s*\(/', "VALUES ('".strtolower((string) Str::ulid())."',", $sql);
    }

    $sql = preg_replace('/\bSELECT\s+(`?)id\b(`?)/', 'SELECT ${1}legacy_id${2} AS id', $sql);

    return preg_replace('/\bWHERE\s+(`?)id\b(`?)/', 'WHERE ${1}legacy_id${2}', $sql);
}
