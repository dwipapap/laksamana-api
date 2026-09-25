<?php

namespace App\Modules\Stock\Services;

use Closure;
use stdClass;

/**
 * Single-record access for /api/v1/stock on top of the same services the
 * compat routes use (so every legacy rule — preserve-if-null, HPP follow,
 * batch join, CK sync — applies to v1 too).
 *
 * The stock tables have no version column, so a record's version is a hash
 * of what is stored: products/vendors hash their row, orders hash the
 * merged order object (columns + data), the snapshot hashes its whole
 * payload. A write through either surface changes it. Writes check it
 * under SELECT … FOR UPDATE inside one transaction.
 */
class StockRecords
{
    public function __construct(
        private readonly StockCatalog $catalog,
        private readonly StockOrders $orders,
        private readonly StockSnapshot $snapshot,
    ) {}

    public static function hash(mixed $v): string
    {
        return substr(sha1(is_string($v) ? $v : StockSupport::enc($v)), 0, 16);
    }

    /** Run $fn in one transaction after checking $lock() still yields $version. */
    private function guarded(Closure $current, ?string $version, Closure $fn): mixed
    {
        return StockSupport::db()->transaction(function () use ($current, $version, $fn) {
            $cur = $current();
            if ($cur === null) {
                throw new StockConflict('not_found');
            }
            if ($version !== null && ! hash_equals($cur['version'], $version)) {
                throw new StockConflict('stale', $cur['record']);
            }

            return $fn($cur);
        });
    }

    // ─────────────────────────────── products & vendors ──

    /** @return list<array{record:array, version:string}> */
    public function catalogList(string $kind): array
    {
        $table = $kind === 'products' ? 'products' : 'vendors';
        $map = $kind === 'products' ? $this->catalog->products() : $this->catalog->vendors();
        $raw = [];
        foreach (StockSupport::db()->select("SELECT `nama`, `data` FROM `$table`") as $r) {
            $raw[(string) $r->nama] = (string) $r->data;
        }
        $out = [];
        foreach ((array) $map as $nama => $rec) {
            $out[] = ['record' => ['nama' => (string) $nama] + (array) $rec, 'version' => self::hash($raw[(string) $nama] ?? '')];
        }

        return $out;
    }

    /** @return array{record:array, version:string}|null */
    public function catalogFind(string $kind, string $nama, bool $lock = false): ?array
    {
        $table = $kind === 'products' ? 'products' : 'vendors';
        $r = StockSupport::db()->selectOne("SELECT `nama`, `data` FROM `$table` WHERE `nama` = ?".($lock ? ' FOR UPDATE' : ''), [$nama]);
        if (! $r) {
            return null;
        }
        if ($kind === 'products') {
            $rec = StockCatalog::normaliseProduct(json_decode((string) $r->data));
        } else {
            $one = $this->catalog->vendors(); // normalisation lives in the list reader
            $rec = ((array) $one)[$r->nama] ?? new stdClass;
        }

        return ['record' => ['nama' => (string) $r->nama] + (array) $rec, 'version' => self::hash((string) $r->data)];
    }

    /**
     * Create (version null, 409 when the name exists) or change one product/vendor.
     * Only the fields present in $f are changed (the legacy preserve-if-null rule);
     * `nama` different from $nama renames (409 when the new name is taken).
     */
    public function catalogSave(string $kind, ?string $nama, array $f, ?string $version): array
    {
        $target = trim(StockSupport::str($f['nama'] ?? $nama ?? ''));
        $fn = function (?array $cur) use ($kind, $nama, $f, $target) {
            if ($target === '') {
                throw new StockConflict('invalid', $kind === 'products' ? 'nama produk kosong' : 'nama vendor kosong');
            }
            if (($cur === null || $target !== $nama) && $this->catalogFind($kind, $target, true)) {
                throw new StockConflict('exists');
            }
            $g = fn (string $k) => array_key_exists($k, $f) ? $f[$k] : null;
            $old = $cur['record'] ?? [];
            $lama = $cur ? $nama : '';
            if ($kind === 'products') {
                $res = $this->catalog->saveProduct($target, $g('utama') ?? ($old['utama'] ?? ''), $g('cadangan') ?? ($old['cadangan'] ?? []), $lama,
                    $g('satuan'), $g('kategori'), $g('area'), $g('caraBeli'), $g('sumber'), $g('packIsi'),
                    $g('packSatuan'), $g('diOutlet'), $g('satuanDasar'), $g('isi'), $g('aktif'));
            } else {
                $res = $this->catalog->saveVendor($target, $g('whatsapp') ?? ($old['whatsapp'] ?? ''), $lama,
                    $g('perluJadwalJemput'), $g('tutupHari'), $g('penerima'), $g('bank'), $g('norek'));
            }
            if (($res['status'] ?? '') === 'error') {
                throw new StockConflict('invalid', $res['message']);
            }

            return ['row' => $this->catalogFind($kind, $target), 'report' => array_diff_key($res, ['status' => 1])];
        };

        if ($nama === null) {
            return StockSupport::db()->transaction(fn () => $fn(null));
        }

        return $this->guarded(fn () => $this->catalogFind($kind, $nama, true), $version, $fn);
    }

    public function catalogDelete(string $kind, string $nama, string $version): void
    {
        $this->guarded(fn () => $this->catalogFind($kind, $nama, true), $version,
            fn () => $kind === 'products' ? $this->catalog->deleteProduct($nama) : $this->catalog->deleteVendor($nama));
    }

    // ─────────────────────────────── orders ──

    public static function orderVersion(stdClass $o): string
    {
        return self::hash($o);
    }

    /**
     * Filters (exact): status, tim, batchId, tglDatang, kedatangan, item; from/to on tglDatang.
     *
     * @return list<array{record:stdClass, version:string}>
     */
    public function orderList(array $f): array
    {
        $map = ['status' => 'status', 'tim' => 'tim', 'batchId' => 'batch_id', 'tglDatang' => 'tgl_datang', 'kedatangan' => 'kedatangan', 'item' => 'item'];
        $sql = 'SELECT * FROM `orders` WHERE 1=1';
        $par = [];
        foreach ($map as $k => $col) {
            if (isset($f[$k]) && is_string($f[$k])) {
                $sql .= " AND `$col` = ?";
                $par[] = $f[$k];
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (isset($f[$k]) && is_string($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $sql .= " AND `tgl_datang` $op ?";
                $par[] = $f[$k];
            }
        }
        $out = [];
        foreach (StockSupport::db()->select($sql.' ORDER BY `row_index`', $par) as $r) {
            $o = StockOrders::fromRow((array) $r);
            $out[] = ['record' => $o, 'version' => self::orderVersion($o)];
        }

        return $out;
    }

    /** @return array{record:stdClass, version:string}|null */
    public function orderFind(string $nomor, bool $lock = false): ?array
    {
        $r = StockSupport::db()->selectOne('SELECT * FROM `orders` WHERE `nomor_order` = ?'.($lock ? ' FOR UPDATE' : ''), [$nomor]);
        if (! $r) {
            return null;
        }
        $o = StockOrders::fromRow((array) $r);

        return ['record' => $o, 'version' => self::orderVersion($o)];
    }

    /**
     * Change one order. Accepted fields (any subset):
     *   qty; kedatangan (+ catatanAktual, tglTerima, catatanTerima) — runs the CK sync;
     *   tglJemput; status ('Aktif' | 'Arsip').
     */
    public function orderPatch(string $nomor, array $f, string $version): array
    {
        return $this->guarded(fn () => $this->orderFind($nomor, true), $version, function (array $cur) use ($nomor, $f) {
            $row = (int) $cur['record']->rowIndex;
            $report = [];
            if (array_key_exists('qty', $f)) {
                $res = $this->orders->updateQty($row, $f['qty']);
                if ($res['status'] === 'error') {
                    throw new StockConflict('invalid', $res['message']);
                }
            }
            if (array_key_exists('tglJemput', $f)) {
                $res = $this->orders->updateTglJemput([(object) ['rowIndex' => $row, 'tglJemput' => $f['tglJemput']]]);
                if ($res['status'] === 'error') {
                    throw new StockConflict('invalid', $res['message']);
                }
            }
            if (array_key_exists('kedatangan', $f)) {
                $u = ['rowIndex' => $row, 'kedatangan' => $f['kedatangan'], 'catatanAktual' => $f['catatanAktual'] ?? ''];
                foreach (['tglTerima', 'catatanTerima'] as $k) {
                    if (array_key_exists($k, $f)) {
                        $u[$k] = $f[$k];
                    }
                }
                $report['ck'] = $this->orders->updateKedatangan([(object) $u])['ck'];
            }
            if (array_key_exists('status', $f)) {
                if (! in_array($f['status'], ['Aktif', 'Arsip'], true)) {
                    throw new StockConflict('invalid', 'status must be Aktif or Arsip');
                }
                $this->orders->setStatus((object) ['orderIds' => [$nomor]], $f['status']);
            }

            return ['row' => $this->orderFind($nomor), 'report' => $report];
        });
    }

    public function orderDelete(string $nomor, string $version): void
    {
        $this->guarded(fn () => $this->orderFind($nomor, true), $version,
            fn (array $cur) => $this->orders->deleteRow((int) $cur['record']->rowIndex));
    }

    // ─────────────────────────────── stock today ──

    /** @return array{record:array, version:string} */
    public function stockToday(): array
    {
        $s = $this->snapshot->read();

        return ['record' => $s, 'version' => self::hash($s)];
    }

    public function replaceStockToday(stdClass $stock, mixed $asOf, string $version): array
    {
        return StockSupport::db()->transaction(function () use ($stock, $asOf, $version) {
            StockSupport::db()->select('SELECT `nama` FROM `stock` FOR UPDATE');
            $cur = $this->stockToday();
            if (! hash_equals($cur['version'], $version)) {
                throw new StockConflict('stale', $cur['record']);
            }
            $res = $this->snapshot->replace($stock, $asOf);
            if ($res['status'] === 'error') {
                throw new StockConflict('invalid', $res['message']);
            }

            return $this->stockToday();
        });
    }
}
