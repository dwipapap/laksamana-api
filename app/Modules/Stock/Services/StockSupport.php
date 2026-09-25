<?php

namespace App\Modules\Stock\Services;

use App\Support\Modules;
use DateTime;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Small helpers every stock service shares (the pur_* utilities of
 * lib_stock_mysql.php / lib_stock_catat.php).
 *
 * JSON rule of the stock backend: decode WITHOUT assoc, so `{}` and `[]`
 * stay distinct (a map that comes back as a list loses data on the next
 * save). Encode like legacy: JSON_UNESCAPED_UNICODE only (slashes escaped).
 *
 * Time: the old code used date(), i.e. the hosting's zone. The stored rows
 * (order times cluster 16:00–01:00) show that zone is WIB, so WIB is made
 * explicit here instead of inheriting Laravel's UTC.
 */
final class StockSupport
{
    public const TZ = 'Asia/Jakarta';

    public static function db(): ConnectionInterface
    {
        return Modules::db('stock');
    }

    /** date($format) in WIB. */
    public static function now(string $format = 'Y-m-d H:i:s'): string
    {
        return (new DateTime('now', new DateTimeZone(self::TZ)))->format($format);
    }

    public static function enc(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    /** pur_data_obj(): the `data` column as an object; broken JSON reads as {}. */
    public static function obj(mixed $raw): stdClass
    {
        $o = json_decode((string) $raw);

        return $o instanceof stdClass ? $o : new stdClass;
    }

    /** pur_lower(). */
    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * PHP's (string) cast without the "Array to string" warning (legacy stored
     * "Array"); an object still throws, which legacy answered with a 500.
     */
    public static function str(mixed $v): string
    {
        return is_array($v) ? 'Array' : (string) $v;
    }

    /** pur_uid(): '<PREFIX>-ymd-His-XXXXXX'. */
    public static function uid(string $prefix): string
    {
        return $prefix.'-'.self::now('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
    }

    /** One row as an assoc array (legacy PDO::FETCH_ASSOC). */
    public static function row(?object $r): ?array
    {
        return $r === null ? null : (array) $r;
    }

    /** @return list<array> */
    public static function rows(array $rs): array
    {
        return array_map(fn ($r) => (array) $r, $rs);
    }
}
