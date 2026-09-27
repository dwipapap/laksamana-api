<?php

namespace App\Modules\Stock\Services;

use Throwable;

/**
 * "Stock Today" — the remaining-stock snapshot shared by Ordering and
 * Purchasing (stock.php). Shape kept from the old Apps Script so the
 * forecasting code reads it unchanged: {stock:{nama:{stock_now,stock_unit}}, as_of, count}.
 *
 * One upload = one full snapshot: the table is rewritten in one transaction.
 * An EMPTY payload is refused, so a broken upload never wipes the stock.
 */
class StockSnapshot
{
    public function read(): array
    {
        $stock = [];
        $asOf = '';
        foreach (StockSupport::db()->select(StockSupport::q('SELECT `nama`,`stock_now`,`stock_unit`,`as_of` FROM {stock} ORDER BY `nama`')) as $r) {
            $stock[$r->nama] = (object) ['stock_now' => (float) $r->stock_now, 'stock_unit' => $r->stock_unit];
            if ($r->as_of > $asOf) {
                $asOf = $r->as_of;
            }
        }

        return ['stock' => (object) $stock, 'as_of' => $asOf, 'count' => count($stock)];
    }

    /** @return array{status:string, saved?:int, as_of?:string, message?:string} */
    public function replace(mixed $stockMap, mixed $asOf): array
    {
        if (! is_object($stockMap) || count((array) $stockMap) === 0) {
            return ['status' => 'error', 'message' => 'stock kosong'];
        }
        $asOf = StockSupport::fit('stock', 'as_of', StockSupport::str($asOf));
        $db = StockSupport::db();
        $db->beginTransaction();
        try {
            $db->delete(StockSupport::q('DELETE FROM {stock}'));
            $n = 0;
            foreach ($stockMap as $nama => $v) {
                $nama = (string) $nama;
                if ($nama === '') {
                    continue;
                }
                $now = (is_object($v) && isset($v->stock_now) && is_numeric($v->stock_now)) ? (float) $v->stock_now : 0;
                $unit = (is_object($v) && isset($v->stock_unit)) ? StockSupport::fit('stock', 'stock_unit', StockSupport::str($v->stock_unit)) : '';
                $db->insert(StockSupport::q('INSERT INTO {stock} (`nama`,`stock_now`,`stock_unit`,`as_of`,`data`) VALUES (?,?,?,?,?)'),
                    [StockSupport::fit('stock', 'nama', $nama), $now, $unit, $asOf, StockSupport::enc($v)]);
                $n++;
            }
            $db->commit();

            return ['status' => 'success', 'saved' => $n, 'as_of' => $asOf];
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
