<?php

namespace App\Modules\Akademi\Services;

use App\Support\Modules;

/**
 * Collection → table map of the akademi database (port of collections(),
 * scalar_keys() in lib_akademi_mysql.php).
 *
 * Only indexed columns are listed; every other field lives in `data`.
 * Reserved-word avoidance (kept exactly): app `division` <-> column `divisi`,
 * app `type` <-> column `kind`.
 */
final class AkademiSchema
{
    /** Legacy app key => core table. */
    public const CORE_TABLES = [
        'users' => 'akademi_users',
        'divisions' => 'akademi_divisions',
        'materials' => 'akademi_materials',
        'programs' => 'akademi_programs',
        'progress' => 'akademi_progress',
        'progProg' => 'akademi_prog_prog',
        'activity' => 'akademi_activity',
    ];

    public const CORE_SETTINGS_TABLE = 'akademi_pengaturan';

    /** Akademi cut over (#57): the Modul reads and writes the core tables. */
    public static function onCore(): bool
    {
        return Modules::connectionName('akademi') === 'core';
    }

    /** Physical table for an app key (a collection/map, 'activity' or 'settings'). */
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

        return match ($key) {
            'progress' => 'progress',
            'progProg' => 'prog_prog',
            'activity' => 'activity',
            default => $key,
        };
    }

    /** Id column for row reads/writes: the legacy id, or `legacy_id` on core. */
    public static function idCol(): string
    {
        return self::onCore() ? 'legacy_id' : 'id';
    }

    /** RowSync defs for the current connection (core defs carry ULID/version). */
    public static function defs(): array
    {
        $defs = self::collections();
        if (! self::onCore()) {
            return $defs;
        }
        foreach ($defs as $key => $def) {
            $defs[$key] = array_merge($def, ['table' => self::CORE_TABLES[$key], 'id' => 'legacy_id', 'ulid' => true, 'versioned' => true, 'coerce' => true]);
        }

        return $defs;
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
            'users' => ['table' => 'users', 'cols' => [
                'name' => ['name', 'str'], 'role' => ['role', 'str'],
                'divisi' => ['division', 'str'], // "div" reserved word in MySQL
                'title' => ['title', 'str'], 'active' => ['active', 'bool'],
            ]],
            'divisions' => ['table' => 'divisions', 'cols' => [
                'name' => ['name', 'str'],
            ]],
            'materials' => ['table' => 'materials', 'created' => true, 'cols' => [
                'title' => ['title', 'str'], 'kind' => ['type', 'str'], // app: type
                'cat' => ['cat', 'str'],
                'mandatory' => ['mandatory', 'bool'], 'published' => ['published', 'bool'],
                'passing' => ['passing', 'int'],
            ]],
            'programs' => ['table' => 'programs', 'created' => true, 'cols' => [
                'title' => ['title', 'str'], 'bulan' => ['bulan', 'str'],
                'deadline' => ['deadline', 'date'],
            ]],
        ];
    }

    /** Top-level keys that are not lists — stored as-is in settings(k,v). */
    public static function scalarKeys(): array
    {
        return ['settings', 'version', 'createdAt'];
    }

    /** Tables counted by stats. */
    public static function statsTables(): array
    {
        return ['users', 'divisions', 'materials', 'programs', 'progress', 'prog_prog', 'activity'];
    }
}
