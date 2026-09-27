<?php

namespace App\Modules\Stock\Services;

use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;
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

    /**
     * Legacy table => its table in `core` (#73, docs/db/stock.md). `stock_settings`
     * and `purchase_requests` do not exist live, so their core names are never
     * created either: they keep failing exactly as legacy does.
     */
    public const CORE_TABLES = [
        'activity_log' => 'stock_activity_log', 'ck_stock' => 'stock_ck',
        'hpp_bahan' => 'stock_hpp_bahan', 'hpp_bulan' => 'stock_hpp_bulan', 'hpp_pakai' => 'stock_hpp_pakai',
        'hpp_resep' => 'stock_hpp_resep', 'hpp_setting' => 'stock_hpp_setting', 'opname' => 'stock_opname',
        'ordering_users' => 'stock_ordering_users', 'orders' => 'stock_orders', 'products' => 'stock_products',
        'serah_terima' => 'stock_serah_terima', 'stock' => 'stock_snapshot', 'usage_events' => 'stock_usage_events',
        'users' => 'stock_users', 'vendors' => 'stock_vendors', 'waste' => 'stock_waste',
        'stock_settings' => 'stock_pengaturan', 'purchase_requests' => 'stock_purchase_requests',
    ];

    /**
     * Every legacy column, in legacy order (what `SELECT *` returned). On core a
     * table whose first column is `id` keeps that legacy id in `legacy_id`
     * (the ULID owns `id`); the others keep their natural key (`nama`, …) as is.
     */
    public const COLUMNS = [
        'activity_log' => ['id', 'waktu', 'tanggal', 'modul', 'aksi', 'aktor', 'tim', 'ringkas', 'data'],
        'ck_stock' => ['id', 'tanggal', 'item', 'arah', 'qty', 'qty_input', 'unit_input', 'sebab', 'status', 'ref', 'tim', 'pic', 'waktu', 'data'],
        'hpp_bahan' => ['nama', 'satuan', 'qty_beli', 'harga_beli', 'vendor', 'produk', 'kategori', 'catatan', 'updated_at', 'updated_by', 'di_purchasing', 'dibeli_jadi', 'sisi_harga'],
        'hpp_bulan' => ['bulan', 'penjualan', 'catatan', 'updated_at', 'updated_by'],
        'hpp_pakai' => ['bulan', 'bahan', 'sa', 'beli', 'resep', 'spoil', 'team', 'rnd', 'comp', 'opname', 'updated_at', 'updated_by'],
        'hpp_resep' => ['id', 'nama', 'jenis', 'tipe', 'seksi', 'yield_qty', 'yield_unit', 'harga_lama', 'harga_baru', 'harga_upsize', 'modal_manual', 'catatan', 'bahan', 'aktif', 'updated_at', 'updated_by', 'di_purchasing', 'kode'],
        'hpp_setting' => ['id', 'data'],
        'opname' => ['id', 'tanggal', 'pic', 'tim', 'status', 'waktu', 'data'],
        'ordering_users' => ['id', 'nama', 'pin', 'role', 'keterangan', 'data'],
        'orders' => ['nomor_order', 'row_index', 'waktu', 'item', 'qty', 'unit', 'tgl_datang', 'pic', 'status', 'kedatangan', 'batch_id', 'batch_name', 'tim', 'data'],
        'products' => ['nama', 'utama', 'data'],
        'serah_terima' => ['id', 'tanggal', 'tujuan', 'penerima', 'pic', 'tim', 'waktu', 'foto', 'foto_nama', 'data'],
        'stock' => ['nama', 'stock_now', 'stock_unit', 'as_of', 'data'],
        'usage_events' => ['id', 'tanggal', 'jenis', 'nama_event', 'status', 'pic', 'tim', 'waktu', 'data'],
        'users' => ['id', 'nama', 'pin', 'role', 'keterangan', 'data'],
        'vendors' => ['nama', 'whatsapp', 'data'],
        'waste' => ['id', 'tanggal', 'item', 'qty', 'unit', 'sebab', 'pic', 'tim', 'waktu', 'foto', 'foto_nama', 'data'],
    ];

    public static function db(): ConnectionInterface
    {
        return Modules::db('stock');
    }

    /** Stock cut over (#73): the Modul reads and writes the stock_* tables of core. */
    public static function onCore(): bool
    {
        return Modules::connectionName('stock') === 'core';
    }

    /** Physical name of a legacy table on the current connection (unquoted). */
    public static function table(string $legacy): string
    {
        return self::onCore() ? self::CORE_TABLES[$legacy] : $legacy;
    }

    /**
     * Every stock statement is written against the legacy names and goes
     * through here: `{waste}` is the physical table, `{id}` the legacy-id
     * column (`legacy_id` on core) and `{*waste}` the legacy column list
     * (`*` on legacy; on core the same columns in the same order, the legacy
     * id aliased back to `id`, so rows and the wire stay identical).
     */
    public static function q(string $sql): string
    {
        return preg_replace_callback('/\{(\*?)(\w+)\}/', function (array $m): string {
            if ($m[1] === '' && $m[2] === 'id') {
                return self::onCore() ? '`legacy_id`' : '`id`';
            }
            if (! isset(self::CORE_TABLES[$m[2]])) {
                throw new RuntimeException('stock SQL: unknown token '.$m[0]);
            }
            if ($m[1] === '') {
                return '`'.self::table($m[2]).'`';
            }
            if (! self::onCore()) {
                return '*';
            }

            return implode(',', array_map(fn ($c) => $c === 'id' ? '`legacy_id` AS `id`' : "`$c`", self::COLUMNS[$m[2]]));
        }, $sql);
    }

    /** date($format) in WIB. */
    public static function now(string $format = 'Y-m-d H:i:s'): string
    {
        return Carbon::now(self::TZ)->format($format);
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

    /** pur_filter_tanggal(): optional ?dari=&ke= (already trimmed), filtered in SQL. */
    public static function dateFilter(string &$sql, array &$par, string $dari, string $ke, string $kolom = 'tanggal'): void
    {
        if ($dari !== '') {
            $sql .= " AND `$kolom` >= ?";
            $par[] = $dari;
        }
        if ($ke !== '') {
            $sql .= " AND `$kolom` <= ?";
            $par[] = $ke;
        }
    }

    /**
     * pur_ada_baris(): does the row exist (an UPDATE's rowCount is 0 for "unchanged"
     * too). Legacy's table list was missing `ck_stock` and `serah_terima`, so asking
     * about them threw (a 500); #113 (owner decision: fix it) adds them — serah
     * terima edit answers success, CK save with an id continues as an edit.
     */
    public static function exists(string $table, string $id): bool
    {
        if (! in_array($table, ['usage_events', 'waste', 'opname', 'orders', 'purchase_requests', 'ck_stock', 'serah_terima'], true)) {
            throw new RuntimeException('Tabel tidak dikenal: '.$table);
        }

        return (bool) self::db()->selectOne(self::q("SELECT 1 AS x FROM {{$table}} WHERE {id}=? LIMIT 1"), [$id]);
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
