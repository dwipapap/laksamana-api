<?php

namespace App\Modules\Marketing\Services;

use App\Support\Modules;

/**
 * Collection → table map of the marketing database (port of collections(),
 * scalar_keys(), kol_settings() in lib_marketing_mysql.php).
 *
 * Only indexed columns are listed; every other field lives in `data`.
 * App key => RowSync definition.
 */
final class MarketingSchema
{
    public const LIB_VERSI = '2026-08-11';

    /** RowSync options used by marketing's saveAll (the strictest variant). */
    public const SYNC = ['conflict' => true, 'sidik' => true, 'capBump' => true, 'skipUnchanged' => true];

    /** Legacy app key => core table. */
    public const CORE_TABLES = [
        'clients' => 'marketing_klien',
        'events' => 'marketing_acara',
        'followups' => 'marketing_tindak_lanjut',
        'approvals' => 'marketing_persetujuan',
        'users' => 'marketing_pengguna',
        'staff' => 'marketing_staf',
        'taskTemplates' => 'marketing_template_tugas',
        'taskCategories' => 'marketing_kategori_tugas',
        'categories' => 'marketing_kategori',
        'notifs' => 'marketing_notifikasi',
        'activities' => 'marketing_aktivitas',
        'designreqs' => 'marketing_permintaan_desain',
        'vip' => 'marketing_vip',
    ];

    public const CORE_SETTINGS_TABLE = 'marketing_pengaturan';

    /** Marketing cut over (#49): the Modul reads and writes the core tables. */
    public static function onCore(): bool
    {
        return Modules::connectionName('marketing') === 'core';
    }

    /** Physical table for an app key (a collection, 'activities' or 'settings'). */
    public static function table(string $key): string
    {
        if ($key === 'settings') {
            return self::onCore() ? self::CORE_SETTINGS_TABLE : 'settings';
        }
        if (self::onCore() && isset(self::CORE_TABLES[$key])) {
            return self::CORE_TABLES[$key];
        }
        if (isset(self::collections()[$key])) {
            return self::collections()[$key]['table'];
        }

        return $key === 'activities' ? 'activities' : $key;
    }

    /** Id column for row reads/writes: the legacy id, or `legacy_id` on core. */
    public static function idCol(): string
    {
        return self::onCore() ? 'legacy_id' : 'id';
    }

    /** RowSync defs for the current connection (core defs carry ULID/version/order). */
    public static function defs(): array
    {
        $defs = self::collections();
        if (! self::onCore()) {
            return $defs;
        }
        foreach ($defs as $key => $def) {
            $defs[$key] = array_merge($def, ['table' => self::CORE_TABLES[$key], 'id' => 'legacy_id', 'ulid' => true, 'versioned' => true]);
        }

        return $defs;
    }

    /** RowSync def for one settings-held collection on the current connection. */
    public static function settingsDef(string $key): array
    {
        if (! self::onCore()) {
            return [];
        }
        $cols = $key === 'vip'
            ? ['tanggal' => ['tanggal', 'date'], 'jenis' => ['jenis', 'str']]
            : [];

        return ['table' => self::CORE_TABLES[$key], 'id' => 'legacy_id', 'ulid' => true,
            'versioned' => true, 'ordered' => true, 'cols' => $cols];
    }

    /** ORDER BY for a created-first listing on the current connection. */
    public static function createdOrder(): string
    {
        return self::onCore() ? 'created_at DESC, legacy_id DESC' : 'created_at DESC, id DESC';
    }

    /** ORDER BY for an id-sorted listing on the current connection. */
    public static function idOrder(): string
    {
        return self::onCore() ? 'legacy_id ASC' : 'id ASC';
    }

    public static function collections(): array
    {
        return [
            'clients' => ['table' => 'clients', 'created' => true, 'cols' => [
                'nama' => ['nama', 'str'], 'perusahaan' => ['perusahaan', 'str'], 'hp' => ['hp', 'str'],
                'email' => ['email', 'str'], 'source' => ['source', 'str'], 'status' => ['status', 'str'],
                'mkt_pic' => ['mktPIC', 'str'], 'last_contact' => ['lastContact', 'date'], 'next_fu' => ['nextFU', 'date'],
            ]],
            'events' => ['table' => 'events', 'created' => true, 'cols' => [
                'client_id' => ['clientId', 'str'], 'nama' => ['nama', 'str'], 'jenis' => ['jenis', 'str'],
                'tanggal' => ['tanggal', 'date'], 'pax' => ['pax', 'int'], 'status' => ['status', 'str'],
                'pipe_col' => ['pipeCol', 'str'], 'mkt_pic' => ['mktPIC', 'str'], 'invoice_sent' => ['invoiceSent', 'bool'],
            ]],
            'followups' => ['table' => 'followups', 'cols' => [
                'client_id' => ['clientId', 'str'], 'event_id' => ['eventId', 'str'], 'by_user' => ['by', 'str'],
                'at_time' => ['at', 'datetime'], 'next_fu' => ['next', 'date'],
            ]],
            'approvals' => ['table' => 'approvals', 'cols' => ['event_id' => ['eventId', 'str'], 'status' => ['status', 'str']]],
            'users' => ['table' => 'users', 'cols' => [
                'name' => ['name', 'str'], 'role' => ['role', 'str'], 'divisi' => ['div', 'str'], 'active' => ['active', 'bool'],
            ]],
            'staff' => ['table' => 'staff', 'cols' => ['nama' => ['nama', 'str'], 'divisi' => ['div', 'str']]],
            'taskTemplates' => ['table' => 'task_templates', 'cols' => ['divisi' => ['div', 'str']]],
            'taskCategories' => ['table' => 'task_categories', 'cols' => []],
            'categories' => ['table' => 'categories', 'cols' => []],
            'notifs' => ['table' => 'notifs', 'cols' => ['at_time' => ['at', 'datetime']]],
        ];
    }

    /** Top-level keys that are not lists — stored as-is in settings(k,v). */
    public static function scalarKeys(): array
    {
        return ['settings', 'baseline', 'rolePerms', 'roleNav'];
    }

    /** Id-keyed row lists stored as one JSON value under settings `extra:<name>`, merged per row. */
    public static function settingsCollections(): array
    {
        return ['designreqs', 'vip'];
    }

    /** Tables counted by stats. */
    public static function statsTables(): array
    {
        return ['clients', 'events', 'followups', 'approvals', 'users', 'staff',
            'task_templates', 'task_categories', 'categories', 'notifs', 'activities'];
    }
}
