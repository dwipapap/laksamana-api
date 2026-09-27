<?php

use App\Modules\Marketing\Services\MarketingSchema;
use App\Support\Modules;

/**
 * A marketing SQL statement in legacy table/id names, run on whichever storage
 * the Modul is on. On core every row table takes the `marketing_` prefix with a
 * renamed tail (`clients` → `marketing_klien`, `settings` → `marketing_pengaturan`,
 * …) and the legacy id moves to `legacy_id` (a fresh ULID becomes the PK); reads
 * alias it back to `id` — the same mapping MarketingSchema::table()/idCol() use.
 * See docs/db/marketing.md.
 */
function mktSql(string $sql): string
{
    if (! MarketingSchema::onCore()) {
        return $sql;
    }
    $sql = preg_replace(
        '/\b(FROM|INTO|UPDATE)\s+(clients|events|followups|approvals|users|staff|task_templates|task_categories|categories|notifs|activities|settings)\b/',
        '$1 marketing_$2', $sql);
    $sql = str_replace(
        ['marketing_clients', 'marketing_events', 'marketing_followups', 'marketing_approvals',
            'marketing_users', 'marketing_staff', 'marketing_task_templates', 'marketing_task_categories',
            'marketing_categories', 'marketing_notifs', 'marketing_activities', 'marketing_settings'],
        ['marketing_klien', 'marketing_acara', 'marketing_tindak_lanjut', 'marketing_persetujuan',
            'marketing_pengguna', 'marketing_staf', 'marketing_template_tugas', 'marketing_kategori_tugas',
            'marketing_kategori', 'marketing_notifikasi', 'marketing_aktivitas', 'marketing_pengaturan'],
        $sql);
    $sql = preg_replace('/\bSELECT id\b/', 'SELECT legacy_id AS id', $sql);

    return preg_replace('/\bWHERE id\b/', 'WHERE legacy_id', $sql);
}

/**
 * The Reservasi VIP list in its legacy shape (array of rows). Legacy keeps it
 * as the `extra:vip` settings JSON; on core it is one `marketing_vip` row per
 * entry, each holding the verbatim row in `data` (see docs/db/marketing.md).
 */
function mktVipList(): array
{
    $db = Modules::db('marketing');
    if (! MarketingSchema::onCore()) {
        $row = $db->selectOne("SELECT v FROM settings WHERE k='extra:vip'");

        return $row ? (json_decode((string) $row->v, true) ?: []) : [];
    }
    $out = [];
    foreach ($db->select('SELECT data FROM marketing_vip ORDER BY urutan, id') as $r) {
        $d = json_decode((string) $r->data, true);
        if (is_array($d)) {
            $out[] = $d;
        }
    }

    return $out;
}
