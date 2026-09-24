<?php

namespace App\Modules\Marketing\Services;

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
