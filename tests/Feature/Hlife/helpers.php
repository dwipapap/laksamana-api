<?php

use App\Modules\Hlife\Services\HlifeState;

/**
 * A hlife SQL read in legacy names, run on whichever storage the Modul is on:
 * hlife_* tables and `legacy_id` (aliased back to `id`) once hlife is on core (#65).
 */
function hlSql(string $sql): string
{
    if (! HlifeState::onCore()) {
        return $sql;
    }
    $sql = preg_replace('/\b(FROM|INTO|UPDATE)\s+(businesses|projects|tasks|goals|dreams|roadmap|content|learning|habits|events|assets|reviews|ledger|settings)\b/', '$1 hlife_$2', $sql);
    $sql = str_replace('hlife_settings', 'hlife_pengaturan', $sql);

    return preg_replace('/\bWHERE id\b/', 'WHERE legacy_id', str_replace('SELECT id,', 'SELECT legacy_id AS id,', preg_replace('/\bSELECT id\b/', 'SELECT legacy_id AS id', $sql)));
}
