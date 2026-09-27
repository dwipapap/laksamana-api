<?php

use App\Modules\Akademi\Services\AkademiSchema;

/**
 * An akademi SQL statement in legacy table/id names, run on whichever storage
 * the Modul is on. On core every row table takes the `akademi_` prefix and the
 * legacy id moves to `legacy_id` (a fresh ULID becomes the PK); reads alias it
 * back to `id` — the same swap AkademiSchema makes with `table()` / `idCol()`.
 * See docs/db/akademi.md for the mapping.
 */
function akSql(string $sql): string
{
    if (! AkademiSchema::onCore()) {
        return $sql;
    }
    $sql = preg_replace(
        '/\b(FROM|INTO|UPDATE)\s+(users|divisions|materials|programs|progress|prog_prog|activity|settings)\b/',
        '$1 akademi_$2', $sql);
    $sql = str_replace('akademi_settings', AkademiSchema::CORE_SETTINGS_TABLE, $sql);
    $sql = preg_replace('/\bSELECT id\b/', 'SELECT legacy_id AS id', $sql);

    return preg_replace('/\bWHERE id\b/', 'WHERE legacy_id', $sql);
}
