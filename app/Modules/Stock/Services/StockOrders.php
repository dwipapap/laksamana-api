<?php

namespace App\Modules\Stock\Services;

use App\Support\Modules;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * The `orders` table, shared by Ordering (crew submit and check in) and
 * Purchasing (monitor, archive, pick-up dates). Port of the ORDERS and
 * "AKSI DARI MODUL ORDERING" sections of lib_stock_mysql.php.
 *
 *  - `data` is the source of truth; the core columns win on read so an
 *    UPDATE of a column (archive) shows without rewriting the JSON.
 *  - Order numbers (LKS-ymd-His-XXX-n) and batch ids (BATCH-ymd-His-HHHH) are
 *    made on the server, in WIB.
 *  - batchOrder runs in one transaction with `MAX(row_index) … FOR UPDATE`
 *    (two crews ordering at once never get the same row_index) and, when
 *    joining a batch, `WHERE batch_id = ? FOR UPDATE`. Joining: the target
 *    batch's date wins, and the same item+unit is summed into the existing
 *    row (which goes back to Aktif so Purchasing sees the addition).
 *  - rowIndex is a POSITION, orderIds (nomor_order) is the identity; archive
 *    prefers orderIds.
 *  - updateKedatangan then syncs Central Kitchen stock; a failing sync never
 *    fails the check-in (it is already saved).
 */
class StockOrders
{
    public function __construct(private readonly StockCk $ck) {}

    /** pur_order_dari_baris(). */
    public static function fromRow(array $r): stdClass
    {
        $o = json_decode((string) $r['data']);
        if (! is_object($o)) {
            $o = new stdClass;
        }
        $o->rowIndex = (int) $r['row_index'];
        $o->nomorOrder = $r['nomor_order'];
        $o->timestamp = $r['waktu'];
        $o->item = $r['item'];
        $o->qty = (float) $r['qty'];
        $o->unit = $r['unit'];
        $o->tglDatang = $r['tgl_datang'];
        $o->pic = $r['pic'];
        $o->status = $r['status'];
        $o->kedatangan = $r['kedatangan'];
        $o->batchId = $r['batch_id'] ?? '';
        $o->batchName = $r['batch_name'] ?? '';
        $o->tim = $r['tim'] ?? '';
        $o->tglJemput = isset($o->tglJemput) ? StockSupport::str($o->tglJemput) : '';
        $o->tglTerima = isset($o->tglTerima) ? StockSupport::str($o->tglTerima) : '';
        $o->catatanTerima = isset($o->catatanTerima) ? StockSupport::str($o->catatanTerima) : '';

        return $o;
    }

    /** @return list<stdClass> */
    public function all(): array
    {
        return array_map([self::class, 'fromRow'], StockSupport::rows(StockSupport::db()->select(StockSupport::q('SELECT {*orders} FROM {orders} ORDER BY `row_index`'))));
    }

    public function find(int $rowIndex): ?stdClass
    {
        $r = StockSupport::db()->selectOne(StockSupport::q('SELECT {*orders} FROM {orders} WHERE `row_index` = ?'), [$rowIndex]);

        return $r ? self::fromRow((array) $r) : null;
    }

    public static function nomorOrder(string $item, int $urut): string
    {
        $kode = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $item) ?: 'XXX', 0, 3));
        if ($kode === '') {
            $kode = 'XXX';
        }

        return 'LKS-'.StockSupport::now('ymd').'-'.StockSupport::now('His').'-'.str_pad($kode, 3, 'X').'-'.$urut;
    }

    public static function batchId(): string
    {
        return 'BATCH-'.StockSupport::now('ymd').'-'.StockSupport::now('His').'-'.strtoupper(bin2hex(random_bytes(2)));
    }

    /** pur_orders_batches(): joinable batches (nothing arrived yet), per team and arrival date. */
    public function batches(string $tim = '', string $tgl = ''): array
    {
        $sql = StockSupport::q("SELECT `batch_id`, `batch_name`, `tgl_datang`, `tim`,
                       MIN(`pic`)   AS pic,
                       MIN(`waktu`) AS waktu,
                       COUNT(*)     AS jml_item,
                       SUM(CASE WHEN `kedatangan` = 'Datang' THEN 1 ELSE 0 END) AS jml_datang,
                       SUM(CASE WHEN `status` = 'Aktif' THEN 1 ELSE 0 END)      AS jml_aktif
                FROM {orders}
                WHERE `batch_id` <> ''");
        $par = [];
        if ($tim !== '') {
            $sql .= ' AND `tim` = ?';
            $par[] = $tim;
        }
        if ($tgl !== '') {
            $sql .= ' AND `tgl_datang` = ?';
            $par[] = $tgl;
        }
        $sql .= ' GROUP BY `batch_id`, `batch_name`, `tgl_datang`, `tim`
                  HAVING jml_datang = 0
                  ORDER BY `tgl_datang` ASC, waktu ASC';
        $out = [];
        foreach (StockSupport::db()->select($sql, $par) as $r) {
            $out[] = [
                'batchId' => $r->batch_id,
                'batchName' => $r->batch_name,
                'tglDatang' => $r->tgl_datang,
                'tim' => $r->tim,
                'pic' => $r->pic,
                'waktu' => $r->waktu,
                'jmlItem' => (int) $r->jml_item,
                'terarsip' => ((int) $r->jml_aktif === 0),
            ];
        }

        return $out;
    }

    /** pur_orders_batch(): one submission — a new batch, or joined into $meta->batchId. */
    public function batchOrder(mixed $orders, mixed $meta = null): array
    {
        if (! is_array($orders) || ! $orders) {
            return ['status' => 'error', 'message' => 'orders kosong'];
        }
        if (! is_object($meta)) {
            $meta = new stdClass;
        }
        $batchId = trim(StockSupport::str($meta->batchId ?? ''));
        $batchName = trim(StockSupport::str($meta->batchName ?? ''));
        $tim = trim(StockSupport::str($meta->tim ?? ''));

        $db = StockSupport::db();
        $db->beginTransaction();
        try {
            $maxRow = (int) $db->selectOne(StockSupport::q('SELECT COALESCE(MAX(`row_index`), 1) AS m FROM {orders} FOR UPDATE'))->m;
            $jml = (int) $db->selectOne(StockSupport::q('SELECT COUNT(*) AS c FROM {orders}'))->c;

            $adaBaris = [];
            $gabung = false;
            $b0 = null;
            if ($batchId !== '') {
                $baris = StockSupport::rows($db->select(StockSupport::q('SELECT {*orders} FROM {orders} WHERE `batch_id` = ? ORDER BY `nomor_order` FOR UPDATE'), [$batchId]));
                if (! $baris) {
                    $db->rollBack();

                    return ['status' => 'error', 'message' => 'batch tujuan tidak ditemukan atau sudah tidak aktif'];
                }
                $gabung = true;
                foreach ($baris as $b) {
                    $adaBaris[StockSupport::lower($b['item'])] = $b;
                }
                $b0 = $baris[0];
                $batchName = $b0['batch_name'] ?? $batchName;
                if ($tim === '') {
                    $tim = $b0['tim'] ?? '';
                }
            } else {
                $batchId = self::batchId();
            }

            $dibuat = [];
            $digabung = [];
            foreach ($orders as $o) {
                if (! is_object($o)) {
                    continue;
                }
                $item = StockSupport::str($o->item ?? '');
                if ($item === '') {
                    continue;
                }
                $qty = isset($o->qty) ? (float) $o->qty : 0;
                $unit = StockSupport::str($o->unit ?? '');
                $waktu = StockSupport::now();
                $tgl = $gabung ? ($b0['tgl_datang'] ?? '-') : StockSupport::str($o->tglDatang ?? '-');

                $kunci = StockSupport::lower($item);
                if ($gabung && isset($adaBaris[$kunci]) && $adaBaris[$kunci]['unit'] === $unit) {
                    $lama = $adaBaris[$kunci];
                    $qtyBaru = (float) $lama['qty'] + $qty;
                    $rec = json_decode((string) $lama['data']);
                    if (! is_object($rec)) {
                        $rec = new stdClass;
                    }
                    $rec->qty = $qtyBaru;
                    $rec->status = 'Aktif';
                    $noteBaru = isset($o->note) && $o->note !== '' && $o->note !== '-' ? StockSupport::str($o->note) : '';
                    if ($noteBaru !== '') {
                        $noteLama = isset($rec->note) && $rec->note !== '-' ? StockSupport::str($rec->note) : '';
                        $rec->note = $noteLama === '' ? $noteBaru : ($noteLama.' | '.$noteBaru);
                    }
                    $db->update(StockSupport::q("UPDATE {orders} SET `qty` = ?, `data` = ?, `status` = 'Aktif' WHERE `nomor_order` = ?"),
                        [$qtyBaru, StockSupport::enc($rec), $lama['nomor_order']]);
                    $adaBaris[$kunci]['qty'] = $qtyBaru;
                    $adaBaris[$kunci]['data'] = StockSupport::enc($rec);
                    $digabung[] = ['item' => $item, 'qty' => $qtyBaru, 'unit' => $unit];

                    continue;
                }

                $urut = $jml + count($dibuat) + 1;
                $nomor = self::nomorOrder($item, $urut);
                $row = $maxRow + count($dibuat) + 1;
                $rec = (object) [
                    'rowIndex' => $row,
                    'nomorOrder' => $nomor,
                    'timestamp' => $waktu,
                    'item' => $item,
                    'qty' => $qty,
                    'unit' => $unit,
                    'note' => isset($o->note) && $o->note !== '' ? $o->note : '-',
                    'tglDatang' => $tgl,
                    'pic' => StockSupport::str($o->pic ?? ''),
                    'status' => 'Aktif',
                    'kedatangan' => '',
                    'catatan' => '',
                    'batchId' => $batchId,
                    'batchName' => $batchName,
                    'tim' => $tim,
                ];
                $db->insert(StockSupport::q('INSERT INTO {orders}
                    (`nomor_order`,`row_index`,`waktu`,`item`,`qty`,`unit`,`tgl_datang`,`pic`,`status`,`kedatangan`,`batch_id`,`batch_name`,`tim`,`data`)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'),
                    [$nomor, $row, $waktu, $item, $rec->qty, $rec->unit, $tgl, $rec->pic, 'Aktif', '',
                        $batchId, $batchName, $tim, StockSupport::enc($rec)]);
                $dibuat[] = $rec;
                $adaBaris[$kunci] = ['nomor_order' => $nomor, 'item' => $item, 'qty' => $qty,
                    'unit' => $unit, 'data' => StockSupport::enc($rec)];
            }

            $db->commit();

            return ['status' => 'success',
                'created' => count($dibuat),
                'merged' => count($digabung),
                'batchId' => $batchId,
                'batchName' => $batchName,
                'orders' => $dibuat,
                'mergedItems' => $digabung];
        } catch (Throwable $e) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** pur_orders_import(): migration tool, idempotent by nomor_order, keeps numbers and statuses. */
    public function import(mixed $orders): array
    {
        if (! is_array($orders) || ! $orders) {
            return ['status' => 'error', 'message' => 'orders kosong'];
        }
        $db = StockSupport::db();
        $db->beginTransaction();
        try {
            $n = $lewat = 0;
            $rowFallback = 100000;
            foreach ($orders as $o) {
                if (! is_object($o)) {
                    $lewat++;

                    continue;
                }
                $nomor = StockSupport::str($o->nomorOrder ?? '');
                if ($nomor === '') {
                    $lewat++;

                    continue;
                }
                $row = isset($o->rowIndex) && is_numeric($o->rowIndex) ? (int) $o->rowIndex : ++$rowFallback;
                $db->statement(StockSupport::q('INSERT INTO {orders}
                    (`nomor_order`,`row_index`,`waktu`,`item`,`qty`,`unit`,`tgl_datang`,`pic`,`status`,`kedatangan`,`data`)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      `row_index`=VALUES(`row_index`), `waktu`=VALUES(`waktu`), `item`=VALUES(`item`),
                      `qty`=VALUES(`qty`), `unit`=VALUES(`unit`), `tgl_datang`=VALUES(`tgl_datang`),
                      `pic`=VALUES(`pic`), `status`=VALUES(`status`), `kedatangan`=VALUES(`kedatangan`),
                      `data`=VALUES(`data`)'), [
                    $nomor, $row,
                    StockSupport::str($o->timestamp ?? ''), StockSupport::str($o->item ?? ''),
                    isset($o->qty) ? (float) $o->qty : 0, StockSupport::str($o->unit ?? ''),
                    StockSupport::str($o->tglDatang ?? ''), StockSupport::str($o->pic ?? ''),
                    StockSupport::str($o->status ?? 'Aktif'), StockSupport::str($o->kedatangan ?? ''),
                    StockSupport::enc($o),
                ]);
                $n++;
            }
            $db->commit();

            return ['status' => 'success', 'imported' => $n, 'skipped' => $lewat];
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** pur_orders_arsip(): orderIds (preferred) or rows / rowIndex. */
    public function setStatus(stdClass $body, string $status): array
    {
        $ids = (isset($body->orderIds) && is_array($body->orderIds)) ? $body->orderIds : [];
        $rows = [];
        if (isset($body->rows) && is_array($body->rows)) {
            $rows = $body->rows;
        } elseif (isset($body->rowIndex)) {
            $rows = [$body->rowIndex];
        }
        $db = StockSupport::db();
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $n = $db->update('UPDATE `'.StockSupport::table('orders')."` SET `status` = ? WHERE `nomor_order` IN ($ph)",
                array_merge([$status], array_map(fn ($x) => StockSupport::str($x), $ids)));
        } elseif ($rows) {
            $rows = array_values(array_filter(array_map(fn ($x) => is_array($x) ? (int) (bool) $x : (int) $x, $rows), fn ($x) => $x > 0));
            if (! $rows) {
                return ['status' => 'error', 'message' => 'rows kosong'];
            }
            $ph = implode(',', array_fill(0, count($rows), '?'));
            $n = $db->update('UPDATE `'.StockSupport::table('orders')."` SET `status` = ? WHERE `row_index` IN ($ph)", array_merge([$status], $rows));
        } else {
            return ['status' => 'error', 'message' => 'tidak ada order yang ditunjuk'];
        }
        // keep `data` in step with the column (legacy rewrites every valid row)
        $db->update(StockSupport::q("UPDATE {orders} SET `data` = JSON_SET(`data`, '$.status', `status`) WHERE JSON_VALID(`data`)"));

        return ['status' => 'success', 'updated' => $n];
    }

    /** pur_orders_update_kedatangan() + the Central Kitchen sync (orders.php). */
    public function updateKedatangan(mixed $updates): array
    {
        $hasil = $this->kedatangan($updates);
        $baris = [];
        foreach ((is_array($updates) ? $updates : []) as $u) {
            if (is_object($u) && isset($u->rowIndex)) {
                $baris[] = (int) (is_array($u->rowIndex) ? (bool) $u->rowIndex : $u->rowIndex);
            }
        }
        try {
            $hasil['ck'] = $this->ck->syncOrders($baris);
        } catch (Throwable $e) {
            Log::warning('[stock/orders] sinkron CK gagal: '.$e->getMessage());
            $hasil['ck'] = ['error' => 'sinkron stok CK gagal'];
        }

        return $hasil;
    }

    private function kedatangan(mixed $updates): array
    {
        if (! is_array($updates) || ! $updates) {
            return ['status' => 'error', 'message' => 'updates kosong'];
        }
        $db = StockSupport::db();
        $db->beginTransaction();
        try {
            $n = 0;
            foreach ($updates as $u) {
                if (! is_object($u) || ! isset($u->rowIndex)) {
                    continue;
                }
                $row = (int) $u->rowIndex;
                if ($row <= 0) {
                    continue;
                }
                $kdt = StockSupport::str($u->kedatangan ?? '');
                $cat = isset($u->catatanAktual) ? StockSupport::str($u->catatanAktual) : '';
                if (isset($u->tglTerima) || isset($u->catatanTerima)) {
                    $tt = trim(StockSupport::str($u->tglTerima ?? ''));
                    if ($tt !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tt)) {
                        $tt = '';
                    }
                    $ct = mb_substr(trim(StockSupport::str($u->catatanTerima ?? '')), 0, 300);
                    $n += $db->update('UPDATE `'.StockSupport::table('orders')."`
                         SET `kedatangan` = ?,
                             `data` = JSON_SET(IF(JSON_VALID(`data`), `data`, '{}'),
                                        '$.kedatangan', ?, '$.catatan', ?,
                                        '$.tglTerima', ?, '$.catatanTerima', ?)
                       WHERE `row_index` = ?", [$kdt, $kdt, $cat, $tt, $ct, $row]);
                } else {
                    $n += $db->update('UPDATE `'.StockSupport::table('orders')."`
                         SET `kedatangan` = ?,
                             `data` = JSON_SET(IF(JSON_VALID(`data`), `data`, '{}'),
                                        '$.kedatangan', ?, '$.catatan', ?)
                       WHERE `row_index` = ?", [$kdt, $kdt, $cat, $row]);
                }
            }
            $db->commit();

            return ['status' => 'success', 'updated' => $n];
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** pur_orders_update_tgl_jemput(): data.tglJemput only (no column); '' cancels the schedule; a bad date stops the whole batch. */
    public function updateTglJemput(mixed $updates): array
    {
        if (! is_array($updates) || ! $updates) {
            return ['status' => 'error', 'message' => 'updates kosong'];
        }
        $db = StockSupport::db();
        $db->beginTransaction();
        try {
            $n = 0;
            foreach ($updates as $u) {
                if (! is_object($u) || ! isset($u->rowIndex)) {
                    continue;
                }
                $row = (int) $u->rowIndex;
                if ($row <= 0) {
                    continue;
                }
                $tgl = trim(StockSupport::str($u->tglJemput ?? ''));
                if ($tgl !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
                    // Legacy returns here with the transaction still open; PHP's PDO
                    // then rolls it back at the end of the request. Same outcome.
                    $db->rollBack();

                    return ['status' => 'error', 'message' => 'format tanggal jemput harus YYYY-MM-DD'];
                }
                $n += $db->update('UPDATE `'.StockSupport::table('orders')."`
                      SET `data` = JSON_SET(IF(JSON_VALID(`data`), `data`, '{}'), '$.tglJemput', ?)
                    WHERE `row_index` = ?", [$tgl, $row]);
            }
            $db->commit();

            return ['status' => 'success', 'updated' => $n];
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** pur_orders_update_qty(). */
    public function updateQty(mixed $rowIndex, mixed $newQty): array
    {
        $row = (int) (is_array($rowIndex) ? (bool) $rowIndex : $rowIndex);
        if ($row <= 0) {
            return ['status' => 'error', 'message' => 'rowIndex tidak sah'];
        }
        if (! is_numeric($newQty)) {
            return ['status' => 'error', 'message' => 'newQty bukan angka'];
        }
        $qty = (float) $newQty;
        $n = StockSupport::db()->update('UPDATE `'.StockSupport::table('orders')."`
               SET `qty` = ?,
                   `data` = JSON_SET(IF(JSON_VALID(`data`), `data`, '{}'), '$.qty', ?)
             WHERE `row_index` = ?", [$qty, $qty, $row]);

        return ['status' => 'success', 'updated' => $n];
    }

    /** pur_orders_delete_row(): a real DELETE (the Ordering delete button). */
    public function deleteRow(mixed $rowIndex): array
    {
        $row = (int) (is_array($rowIndex) ? (bool) $rowIndex : $rowIndex);
        if ($row <= 0) {
            return ['status' => 'error', 'message' => 'rowIndex tidak sah'];
        }

        return ['status' => 'success', 'deleted' => StockSupport::db()->delete(StockSupport::q('DELETE FROM {orders} WHERE `row_index` = ?'), [$row])];
    }

    /** pur_stats(). */
    public function stats(): array
    {
        $db = StockSupport::db();
        $out = [];
        foreach (['orders', 'vendors', 'products', 'users'] as $t) {
            $out[$t] = (int) $db->selectOne(StockSupport::q("SELECT COUNT(*) AS c FROM {{$t}}"))->c;
        }
        $out['orders_aktif'] = (int) $db->selectOne(StockSupport::q("SELECT COUNT(*) AS c FROM {orders} WHERE `status` = 'Aktif'"))->c;
        $out['env'] = Modules::envLabel();
        $out['db'] = Modules::databaseName('stock');

        return $out;
    }
}
