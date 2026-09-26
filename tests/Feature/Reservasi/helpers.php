<?php

use App\Modules\Reservasi\Services\ReservasiState;

/**
 * A reservasi SQL read in legacy names, run on whichever storage the Modul is
 * on: reservasi_* tables and `legacy_id` once reservasi is on core (#71).
 */
function rsSql(string $sql): string
{
    if (! ReservasiState::onCore()) {
        return $sql;
    }
    $sql = preg_replace('/\b(FROM|INTO|UPDATE)\s+(reservations|audit|settings)\b/', '$1 reservasi_$2', $sql);
    $sql = str_replace('reservasi_settings', 'reservasi_pengaturan', $sql);
    $sql = preg_replace('/\bSELECT id\b/', 'SELECT legacy_id AS id', $sql);

    return preg_replace('/\bWHERE id\b/', 'WHERE legacy_id', $sql);
}
