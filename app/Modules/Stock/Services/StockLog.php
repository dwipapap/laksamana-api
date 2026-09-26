<?php

namespace App\Modules\Stock\Services;

use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Activity log of Ordering + Purchasing (lib_stock_log.php, table `activity_log`).
 * One row = one action that CHANGED something. `waktu` is always the server's
 * clock (crew phones drift). Logging must never break the work: every failure
 * is swallowed — a write answers {status:'success', dicatat:0}, a read [].
 * The legacy CREATE TABLE IF NOT EXISTS is not ported: the table exists live,
 * and when it does not, the swallowed error gives the same answers.
 */
class StockLog
{
    /** pur_log_catat(): several entries in one request. */
    public function write(mixed $entri): array
    {
        try {
            if (! is_array($entri) || ! $entri) {
                return ['status' => 'success', 'dicatat' => 0];
            }
            $n = 0;
            foreach ($entri as $e) {
                if (! is_object($e)) {
                    continue;
                }
                $modul = trim(StockSupport::str($e->modul ?? ''));
                $aksi = trim(StockSupport::str($e->aksi ?? ''));
                if ($modul === '' || $aksi === '') {
                    continue;
                }
                $waktu = StockSupport::now();
                StockSupport::db()->insert(StockSupport::q('INSERT INTO {activity_log}
                    ({id},`waktu`,`tanggal`,`modul`,`aksi`,`aktor`,`tim`,`ringkas`,`data`)
                    VALUES (?,?,?,?,?,?,?,?,?)'), [
                    StockSupport::uid('LOG'), $waktu, substr($waktu, 0, 10), $modul, $aksi,
                    trim(StockSupport::str($e->aktor ?? '')), trim(StockSupport::str($e->tim ?? '')),
                    mb_substr(trim(StockSupport::str($e->ringkas ?? '')), 0, 500),
                    StockSupport::enc($e->data ?? new stdClass),
                ]);
                $n++;
            }

            return ['status' => 'success', 'dicatat' => $n];
        } catch (Throwable $ex) {
            Log::error('[stock/log] gagal mencatat: '.$ex->getMessage());

            return ['status' => 'success', 'dicatat' => 0];
        }
    }

    /** pur_log_ambil(): newest first, always limited (default 300, max 2000). */
    public function read(string $dari = '', string $ke = '', string $modul = '', string $cari = '', int $limit = 300): array
    {
        try {
            $sql = StockSupport::q('SELECT {*activity_log} FROM {activity_log} WHERE 1=1');
            $par = [];
            StockSupport::dateFilter($sql, $par, $dari, $ke);
            if ($modul !== '') {
                $sql .= ' AND `modul` = ?';
                $par[] = $modul;
            }
            if ($cari !== '') {
                $sql .= ' AND (`ringkas` LIKE ? OR `aktor` LIKE ? OR `aksi` LIKE ?)';
                $k = '%'.$cari.'%';
                array_push($par, $k, $k, $k);
            }
            if ($limit <= 0 || $limit > 2000) {
                $limit = 300;
            }
            $out = [];
            foreach (StockSupport::db()->select($sql.StockSupport::q(' ORDER BY `waktu` DESC, {id} DESC LIMIT ').$limit, $par) as $r) {
                $d = json_decode((string) $r->data);
                $out[] = [
                    'id' => $r->id, 'waktu' => $r->waktu, 'tanggal' => $r->tanggal,
                    'modul' => $r->modul, 'aksi' => $r->aksi, 'aktor' => $r->aktor,
                    'tim' => $r->tim, 'ringkas' => $r->ringkas,
                    'data' => is_object($d) || is_array($d) ? $d : new stdClass,
                ];
            }

            return $out;
        } catch (Throwable $ex) {
            Log::error('[stock/log] gagal membaca: '.$ex->getMessage());

            return [];
        }
    }
}
