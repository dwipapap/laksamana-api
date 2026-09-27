<?php

namespace App\Modules\Stock\Services;

use stdClass;

/**
 * Central Kitchen (lib_stock_ck.php): the CK product master (products with
 * sumber 'ck'/'both'), the ledger behind ck.php (balance, movements, manual
 * save/delete, outlet deliveries) and the order-arrival sync used by orders.
 *
 * Two rules that keep the balance right: the balance is never stored (always
 * SUM(masuk) - SUM(keluar) of ck_stock), and every qty is stored in the base
 * unit, converted once at the door (keDasar).
 */
class StockCk
{
    /** pur_ck_produk(): name => {sumber, packIsi, packSatuan, kategori, satuan, diOutlet}. */
    public function products(): array
    {
        $out = [];
        foreach (StockSupport::db()->select(StockSupport::q('SELECT `nama`,`data` FROM {products} ORDER BY `nama`')) as $r) {
            $d = json_decode((string) $r->data);
            if (! is_object($d)) {
                continue;
            }
            $sumber = isset($d->sumber) ? StockSupport::str($d->sumber) : '';
            if ($sumber !== 'ck' && $sumber !== 'both') {
                continue;
            }
            $out[$r->nama] = (object) [
                'sumber' => $sumber,
                'packIsi' => isset($d->packIsi) ? (float) $d->packIsi : 0,
                'packSatuan' => isset($d->packSatuan) ? StockSupport::str($d->packSatuan) : '',
                'kategori' => isset($d->kategori) ? StockSupport::str($d->kategori) : '',
                'satuan' => isset($d->satuan) && is_array($d->satuan) ? $d->satuan : [],
                'diOutlet' => ($sumber === 'both') || ! empty($d->diOutlet),
            ];
        }

        return $out;
    }

    /** pur_ck_ke_dasar(): a Pack becomes packIsi base units; unknown units are taken as-is. */
    public static function keDasar(mixed $qty, mixed $unit, mixed $packIsi, mixed $packSatuan): float
    {
        $qty = (float) $qty;
        $unit = trim(StockSupport::str($unit));
        if (strcasecmp($unit, 'Pack') === 0 && (float) $packIsi > 0) {
            return $qty * (float) $packIsi;
        }

        return $qty;
    }

    /**
     * pur_ck_sinkron_order(): an order from the Central Kitchen tab (batch_name
     * 'Central Kitchen') marked Datang writes one 'keluar' row (ref = nomor_order,
     * idempotent through UNIQUE(ref, arah)); a cancelled arrival deletes it.
     */
    public function syncOrders(array $rowIndexes): array
    {
        $rows = array_values(array_filter(array_map('intval', $rowIndexes), fn ($n) => $n > 0));
        if (! $rows) {
            return ['disinkron' => 0];
        }
        $produk = $this->products();
        if (! $produk) {
            return ['disinkron' => 0];
        }
        $petaLower = [];
        foreach ($produk as $nama => $p) {
            $petaLower[StockSupport::lower((string) $nama)] = $nama;
        }

        $db = StockSupport::db();
        $ph = implode(',', array_fill(0, count($rows), '?'));
        $n = 0;
        foreach ($db->select('SELECT `nomor_order`,`row_index`,`item`,`qty`,`unit`,`tgl_datang`,
                                     `kedatangan`,`tim`,`pic`,`batch_name`
                                FROM `'.StockSupport::table('orders')."` WHERE `row_index` IN ($ph)", $rows) as $o) {
            if (strtolower(trim((string) ($o->batch_name ?? ''))) !== 'central kitchen') {
                continue; // only the Central Kitchen tab touches CK stock
            }
            $namaMaster = isset($produk[$o->item]) ? $o->item : ($petaLower[StockSupport::lower((string) $o->item)] ?? '');
            if ($namaMaster === '') {
                continue;
            }
            $p = $produk[$namaMaster];
            $ref = (string) $o->nomor_order;
            if ($ref === '') {
                continue;
            }
            if ((string) $o->kedatangan !== 'Datang') {
                $n += $db->delete(StockSupport::q("DELETE FROM {ck_stock} WHERE `ref` = ? AND `arah` = 'keluar'"), [$ref]);

                continue;
            }
            $qtyInput = (float) $o->qty;
            $unit = (string) $o->unit;
            $qty = self::keDasar($qtyInput, $unit, $p->packIsi, $p->packSatuan);
            $rec = (object) ['catatan' => 'Pengajuan '.$ref, 'packIsi' => $p->packIsi, 'packSatuan' => $p->packSatuan];
            $db->statement(
                StockSupport::q("INSERT INTO {ck_stock}
                   ({id},`tanggal`,`item`,`arah`,`qty`,`qty_input`,`unit_input`,`sebab`,`ref`,`tim`,`pic`,`waktu`,`data`)
                 VALUES (?,?,?,'keluar',?,?,?,'pengajuan',?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   `tanggal`=VALUES(`tanggal`), `item`=VALUES(`item`), `qty`=VALUES(`qty`),
                   `qty_input`=VALUES(`qty_input`), `unit_input`=VALUES(`unit_input`),
                   `tim`=VALUES(`tim`), `pic`=VALUES(`pic`), `data`=VALUES(`data`)"), [
                    StockSupport::uid('CKO'),
                    (string) $o->tgl_datang ?: StockSupport::now('Y-m-d'),
                    $namaMaster, $qty, $qtyInput, $unit, $ref,
                    (string) $o->tim, (string) $o->pic, StockSupport::now(),
                    StockSupport::enc($rec),
                ]);
            $n++;
        }

        return ['disinkron' => $n];
    }

    // ─────────────────────────────── ledger (ck.php) ──

    /**
     * pur_ck_saldo(): one row per CK product (0 when it has no movement yet), plus
     * movements of items no longer CK products, flagged `hilang`. Every row counts,
     * whatever its `status` (the pending step was dropped 31 July 2026).
     */
    public function balance(): array
    {
        $produk = $this->products();
        $agg = [];
        foreach (StockSupport::db()->select(StockSupport::q("SELECT `item`,
                 SUM(CASE WHEN `arah`='masuk'  THEN `qty` ELSE 0 END) AS masuk,
                 SUM(CASE WHEN `arah`='keluar' THEN `qty` ELSE 0 END) AS keluar,
                 MAX(`tanggal`) AS terakhir
            FROM {ck_stock} GROUP BY `item`")) as $r) {
            $agg[$r->item] = $r;
        }

        $out = [];
        foreach ($produk as $nama => $p) {
            $a = $agg[$nama] ?? null;
            $masuk = $a ? (float) $a->masuk : 0;
            $keluar = $a ? (float) $a->keluar : 0;
            $out[$nama] = [
                'item' => $nama,
                'sumber' => $p->sumber,
                'packIsi' => $p->packIsi,
                'packSatuan' => $p->packSatuan,
                'kategori' => $p->kategori,
                'masuk' => $masuk,
                'keluar' => $keluar,
                'saldo' => $masuk - $keluar,
                'terakhir' => $a ? (string) $a->terakhir : '',
            ];
        }
        foreach ($agg as $nama => $a) {
            if (isset($out[$nama])) {
                continue;
            }
            $masuk = (float) $a->masuk;
            $keluar = (float) $a->keluar;
            if ($masuk == 0 && $keluar == 0) {
                continue;
            }
            $out[$nama] = [
                'item' => $nama, 'packIsi' => 0, 'packSatuan' => '', 'kategori' => '',
                'masuk' => $masuk, 'keluar' => $keluar, 'saldo' => $masuk - $keluar,
                'terakhir' => (string) $a->terakhir, 'hilang' => true,
            ];
        }
        ksort($out);

        return array_values($out);
    }

    /** pur_ck_mutasi_ambil(): newest first, optional ?dari=&ke= on tanggal. */
    public function movements(string $dari = '', string $ke = '', ?string $id = null): array
    {
        $sql = StockSupport::q('SELECT {id} AS `id`,`tanggal`,`item`,`arah`,`qty`,`qty_input`,`unit_input`,
                       `sebab`,`status`,`ref`,`tim`,`pic`,`waktu`,`data`
                  FROM {ck_stock} WHERE 1=1');
        $par = [];
        if ($id !== null) {
            $sql .= StockSupport::q(' AND {id} = ?');
            $par[] = $id;
        }
        StockSupport::dateFilter($sql, $par, $dari, $ke);
        $out = [];
        foreach (StockSupport::db()->select($sql.' ORDER BY `tanggal` DESC, `waktu` DESC', $par) as $r) {
            $d = StockSupport::obj($r->data);
            $out[] = [
                'id' => $r->id,
                'tanggal' => $r->tanggal,
                'item' => $r->item,
                'arah' => $r->arah,
                'qty' => (float) $r->qty,
                'qtyInput' => (float) $r->qty_input,
                'unitInput' => $r->unit_input,
                'sebab' => $r->sebab,
                'status' => $r->status,
                'ref' => $r->ref === null ? '' : $r->ref,
                'tim' => $r->tim,
                'pic' => $r->pic,
                'waktu' => $r->waktu,
                'catatan' => isset($d->catatan) ? StockSupport::str($d->catatan) : '',
                // pack size AT THE TIME of the movement, not today's master
                'packIsi' => isset($d->packIsi) ? (float) $d->packIsi : 0,
                'packSatuan' => isset($d->packSatuan) ? StockSupport::str($d->packSatuan) : '',
            ];
        }

        return $out;
    }

    /**
     * pur_ck_simpan(): a manual movement (produksi, penyesuaian, rusak). `ref` is
     * never set here (it belongs to the order sync). Pack size comes from the
     * master, never from the browser.
     *
     * With a non-empty `id` this is an edit under the same rules as the v1
     * PATCH: order-sync rows (with a `ref`) are refused, and an unknown id is a
     * clean legacy error. (#113 owner decision: fix it — legacy's pur_ada_baris()
     * table list was missing ck_stock, so this path answered a 500.)
     */
    public function save(stdClass $b, bool $verified = false): array
    {
        $id = trim(StockSupport::str($b->id ?? ''));
        $item = trim(StockSupport::str($b->item ?? ''));
        $arah = strtolower(trim(StockSupport::str($b->arah ?? '')));
        $sebab = strtolower(trim(StockSupport::str($b->sebab ?? '')));

        if ($item === '') {
            return ['status' => 'error', 'message' => 'barang kosong'];
        }
        if ($arah !== 'masuk' && $arah !== 'keluar') {
            return ['status' => 'error', 'message' => 'arah harus masuk/keluar'];
        }
        $qtyInput = (float) ($b->qtyInput ?? 0);
        if ($qtyInput <= 0) {
            return ['status' => 'error', 'message' => 'jumlah harus lebih dari 0'];
        }
        $p = $this->products()[$item] ?? null;
        if (! $p) {
            return ['status' => 'error', 'message' => 'barang bukan barang Central Kitchen: '.$item];
        }
        $unitInput = trim(StockSupport::str($b->unitInput ?? ''));
        if ($unitInput === '') {
            $unitInput = $p->packSatuan !== '' ? $p->packSatuan : 'Pcs';
        }
        $qty = self::keDasar($qtyInput, $unitInput, $p->packIsi, $p->packSatuan);
        if (! in_array($sebab, ['produksi', 'penyesuaian', 'rusak', 'pengajuan'], true)) {
            $sebab = $arah === 'masuk' ? 'produksi' : 'penyesuaian';
        }
        $rec = (object) ['catatan' => trim(StockSupport::str($b->catatan ?? '')), 'packIsi' => $p->packIsi, 'packSatuan' => $p->packSatuan];
        $tanggal = trim(StockSupport::str($b->tanggal ?? '')) ?: StockSupport::now('Y-m-d');
        $waktu = StockSupport::now();
        $tim = trim(StockSupport::str($b->tim ?? ''));
        $pic = trim(StockSupport::str($b->pic ?? ''));
        $dataJson = StockSupport::enc($rec);
        $db = StockSupport::db();

        if ($id !== '') {
            if (! $verified && ! StockSupport::exists('ck_stock', $id)) {
                return ['status' => 'error', 'message' => 'mutasi tidak ditemukan'];
            }
            if ((string) $db->selectOne(StockSupport::q('SELECT `ref` FROM {ck_stock} WHERE {id}=?'), [$id])?->ref !== '') {
                return ['status' => 'error', 'message' => 'mutasi dari pengajuan hanya berubah lewat check-in'];
            }
            $db->update(StockSupport::q('UPDATE {ck_stock} SET `tanggal`=?,`item`=?,`arah`=?,`qty`=?,`qty_input`=?,
                             `unit_input`=?,`sebab`=?,`tim`=?,`pic`=?,`data`=? WHERE {id}=?'),
                [$tanggal, $item, $arah, $qty, $qtyInput, $unitInput, $sebab, $tim, $pic, $dataJson, $id]);

            return ['status' => 'success', 'id' => $id];
        }

        $id = StockSupport::uid('CK');
        $db->insert(StockSupport::q('INSERT INTO {ck_stock}
                       ({id},`tanggal`,`item`,`arah`,`qty`,`qty_input`,`unit_input`,
                        `sebab`,`ref`,`tim`,`pic`,`waktu`,`data`)
                     VALUES (?,?,?,?,?,?,?,?,NULL,?,?,?,?)'),
            [$id, $tanggal, $item, $arah, $qty, $qtyInput, $unitInput, $sebab, $tim, $pic, $waktu, $dataJson]);

        return ['status' => 'success', 'id' => $id];
    }

    /** pur_ck_hapus(): order-sync rows (with a ref) only go away when the check-in is undone. */
    public function delete(mixed $id): array
    {
        $id = trim(StockSupport::str($id));
        if ($id === '') {
            return ['status' => 'error', 'message' => 'id kosong'];
        }
        $row = StockSupport::db()->selectOne(StockSupport::q('SELECT `ref` FROM {ck_stock} WHERE {id}=?'), [$id]);
        if (! $row) {
            return ['status' => 'error', 'message' => 'mutasi tidak ditemukan'];
        }
        if ((string) $row->ref !== '') {
            return ['status' => 'error', 'message' => 'mutasi dari pengajuan hanya hilang bila check-in dibatalkan'];
        }

        return ['status' => 'success', 'deleted' => StockSupport::db()->delete(StockSupport::q('DELETE FROM {ck_stock} WHERE {id}=?'), [$id])];
    }

    /**
     * pur_ck_kiriman_simpan(): an outlet sends goods back to CK; the balance rises at
     * once (one step since 31 July 2026). Only for goods kept at the outlet (diOutlet).
     */
    public function send(stdClass $b): array
    {
        $item = trim(StockSupport::str($b->item ?? ''));
        if ($item === '') {
            return ['status' => 'error', 'message' => 'barang kosong'];
        }
        $qtyInput = (float) ($b->qtyInput ?? 0);
        if ($qtyInput <= 0) {
            return ['status' => 'error', 'message' => 'jumlah harus lebih dari 0'];
        }
        $p = $this->products()[$item] ?? null;
        if (! $p) {
            return ['status' => 'error', 'message' => 'barang bukan barang Central Kitchen: '.$item];
        }
        if (! $p->diOutlet) {
            return ['status' => 'error', 'message' => 'barang ini tidak disimpan di outlet, jadi tidak bisa dikirim ke CK'];
        }
        $unitInput = trim(StockSupport::str($b->unitInput ?? ''));
        if ($unitInput === '') {
            $unitInput = $p->packSatuan !== '' ? $p->packSatuan : 'Pcs';
        }
        $qty = self::keDasar($qtyInput, $unitInput, $p->packIsi, $p->packSatuan);
        $rec = (object) ['catatan' => trim(StockSupport::str($b->catatan ?? '')), 'packIsi' => $p->packIsi, 'packSatuan' => $p->packSatuan];

        $id = StockSupport::uid('CKK');
        StockSupport::db()->insert(StockSupport::q("INSERT INTO {ck_stock}
                       ({id},`tanggal`,`item`,`arah`,`qty`,`qty_input`,`unit_input`,
                        `sebab`,`status`,`ref`,`tim`,`pic`,`waktu`,`data`)
                     VALUES (?,?,?,'masuk',?,?,?,'kiriman','',NULL,?,?,?,?)"), [
            $id,
            trim(StockSupport::str($b->tanggal ?? '')) ?: StockSupport::now('Y-m-d'),
            $item, $qty, $qtyInput, $unitInput,
            trim(StockSupport::str($b->tim ?? '')),
            trim(StockSupport::str($b->pic ?? '')),
            StockSupport::now(),
            StockSupport::enc($rec),
        ]);

        return ['status' => 'success', 'id' => $id];
    }
}
