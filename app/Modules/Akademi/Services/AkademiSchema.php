<?php

namespace App\Modules\Akademi\Services;

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
