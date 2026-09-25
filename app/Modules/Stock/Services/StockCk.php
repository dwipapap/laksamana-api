<?php

namespace App\Modules\Stock\Services;

/**
 * Central Kitchen pieces of lib_stock_ck.php needed by orders: the CK product
 * master (products with sumber 'ck'/'both') and the order-arrival sync.
 * (The CK ledger endpoint ck.php itself is ported with #35.)
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
        foreach (StockSupport::db()->select('SELECT `nama`,`data` FROM `products` ORDER BY `nama`') as $r) {
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
        foreach ($db->select("SELECT `nomor_order`,`row_index`,`item`,`qty`,`unit`,`tgl_datang`,
                                     `kedatangan`,`tim`,`pic`,`batch_name`
                                FROM `orders` WHERE `row_index` IN ($ph)", $rows) as $o) {
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
                $n += $db->delete("DELETE FROM `ck_stock` WHERE `ref` = ? AND `arah` = 'keluar'", [$ref]);

                continue;
            }
            $qtyInput = (float) $o->qty;
            $unit = (string) $o->unit;
            $qty = self::keDasar($qtyInput, $unit, $p->packIsi, $p->packSatuan);
            $rec = (object) ['catatan' => 'Pengajuan '.$ref, 'packIsi' => $p->packIsi, 'packSatuan' => $p->packSatuan];
            $db->statement(
                "INSERT INTO `ck_stock`
                   (`id`,`tanggal`,`item`,`arah`,`qty`,`qty_input`,`unit_input`,`sebab`,`ref`,`tim`,`pic`,`waktu`,`data`)
                 VALUES (?,?,?,'keluar',?,?,?,'pengajuan',?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   `tanggal`=VALUES(`tanggal`), `item`=VALUES(`item`), `qty`=VALUES(`qty`),
                   `qty_input`=VALUES(`qty_input`), `unit_input`=VALUES(`unit_input`),
                   `tim`=VALUES(`tim`), `pic`=VALUES(`pic`), `data`=VALUES(`data`)", [
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
}
