<?php

use App\Modules\Bd\Services\BdState;

/**
 * A bd SQL read in legacy names, run on whichever storage the Modul is on:
 * bd_* tables and `legacy_id` (aliased back to `id`) once bd is on core (#59).
 */
function bdSql(string $sql): string
{
    if (! BdState::onCore()) {
        return $sql;
    }
    $sql = preg_replace('/\b(FROM|INTO|UPDATE)\s+(tasks|purchase_orders|settings|people|projects)\b/', '$1 '.'bd_$2', $sql);
    $sql = str_replace('bd_settings', 'bd_pengaturan', $sql);
    $sql = preg_replace('/\bSELECT id\b/', 'SELECT legacy_id AS id', $sql);

    return preg_replace('/\bWHERE id\b/', 'WHERE legacy_id', $sql);
}
