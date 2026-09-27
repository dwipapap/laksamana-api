<?php

use App\Modules\Finance\Services\KasKecil;

/**
 * A finance SQL read in legacy names, run on whichever storage the Modul is on:
 * the finance_* tables and `legacy_id` (aliased back to `id`) once finance is on
 * core (#67). Columns holding row-to-row ids (kategori_id, trx_id, pos_id) keep
 * their legacy values, so only the id and the table name change.
 *
 * One rename: bk_state's free-text `updated_by` became diubah_oleh, because
 * ADR-0003 reserves updated_by for the ULID actor column.
 *
 * Pest only collects files matching PHPUnit's `*Test.php` suffix, so a helper
 * file like this one is never auto-included: every test file that needs it says
 * `require_once __DIR__.'/helpers.php';` (the bd convention).
 */
function finSql(string $sql): string
{
    if (! KasKecil::onCore()) {
        return $sql;
    }
    $sql = preg_replace(
        '/\b(FROM|INTO|UPDATE|JOIN)\s+(kk_pos|kk_kategori|kk_trx|kk_trx_pos|kk_akses|kk_peran|bk_state|bk_akses|bk_peran|inv_kwitansi|inv_penanda|inv_setting)\b/',
        '$1 finance_$2',
        $sql
    );
    $sql = preg_replace('/\b(SELECT|,)\s*id\b/', '$1 legacy_id AS id', $sql);
    // bk_state's free-text `updated_by` is diubah_oleh on core (ADR-0003 keeps
    // updated_by for the ULID actor column); the legacy name stays readable.
    // Two anchored patterns, so neither rewrite can eat the other's alias.
    $sql = preg_replace('/\bSELECT\s+updated_by\b/', 'SELECT diubah_oleh AS updated_by', $sql);
    $sql = preg_replace('/\bWHERE\s+updated_by\b/', 'WHERE diubah_oleh', $sql);

    return preg_replace('/\bWHERE id\b/', 'WHERE legacy_id', $sql);
}
