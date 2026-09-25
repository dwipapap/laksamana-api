<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single place that answers "which database / folder does module X use".
 *
 * Every model and service goes through here instead of naming a connection,
 * so consolidating a module into the `core` database later is a config change
 * (config/laksamana.php) and nothing else.
 */
final class Modules
{
    public static function config(string $module): array
    {
        $m = config("laksamana.modules.$module");
        if (! is_array($m)) {
            throw new InvalidArgumentException("Unknown laksamana module [$module].");
        }

        return $m;
    }

    public static function connectionName(string $module): string
    {
        return self::config($module)['connection'];
    }

    public static function db(string $module): ConnectionInterface
    {
        return DB::connection(self::connectionName($module));
    }

    /** Actual database name behind a module (what legacy ping/stats report as `db`). */
    public static function databaseName(string $module): string
    {
        return (string) config('database.connections.'.self::connectionName($module).'.database');
    }

    public static function dataDir(string $module): ?string
    {
        return self::config($module)['data_dir'] ?? null;
    }

    public static function isInMaintenance(string $module): bool
    {
        return (bool) (self::config($module)['maintenance'] ?? false);
    }

    public static function envLabel(): string
    {
        return (string) config('laksamana.env_label', 'lokal');
    }

    /** @return array<string,array> */
    public static function all(): array
    {
        return config('laksamana.modules', []);
    }
}
