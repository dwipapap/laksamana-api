<?php

use App\Modules\Konten\Services\KontenSchema;

/**
 * A konten SQL statement in legacy table/id names, run on whichever storage the
 * Modul is on. On core every row table takes the `konten_` prefix (`settings`
 * → `konten_pengaturan`) and the legacy id moves to `legacy_id` (a fresh ULID
 * becomes the PK); reads alias it back to `id` — the same swap KontenSchema
 * makes with `table()` / `idCol()`. See docs/db/konten.md for the mapping.
 */
function ktSql(string $sql): string
{
    if (! KontenSchema::onCore()) {
        return $sql;
    }
    $sql = preg_replace(
        '/\b(FROM|INTO|UPDATE)\s+(users|brands|campaigns|content|prod_tasks|shootings|assets|bank|kols|visits|ads|ad_funds|notifs|logs|settings)\b/',
        '$1 konten_$2', $sql);
    $sql = str_replace('konten_settings', KontenSchema::CORE_SETTINGS_TABLE, $sql);
    $sql = preg_replace('/\bSELECT id\b/', 'SELECT legacy_id AS id', $sql);

    return preg_replace('/\bWHERE id\b/', 'WHERE legacy_id', $sql);
}
