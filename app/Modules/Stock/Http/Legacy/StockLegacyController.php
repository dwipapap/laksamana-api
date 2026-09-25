<?php

namespace App\Modules\Stock\Http\Legacy;

use App\Modules\Stock\Services\StockCatalog;
use App\Modules\Stock\Services\StockCk;
use App\Modules\Stock\Services\StockEntries;
use App\Modules\Stock\Services\StockLog;
use App\Modules\Stock\Services\StockOrders;
use App\Modules\Stock\Services\StockSnapshot;
use App\Modules\Stock\Services\StockSupport;
use App\Modules\Stock\Services\StockTeamScope;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stand-in for the per-file stock-mysql endpoints: `/stock-api-mysql/<file>.php`.
 *
 * Shared plumbing of _boot.php + lib_stock_mysql.php, kept exactly:
 *  - `GET ?action=ping` answers BEFORE the token check and without the DB:
 *    {status:'success', ok:true, time, env, db}
 *  - raw bodies (not {ok,data}): maps, bare arrays, {status:'success',…}
 *  - errors {status:'error', message} with real HTTP codes: 400 bad JSON /
 *    unknown action, 403 token, 405 method, 500 'kesalahan server' for any
 *    exception (the message is logged, never shown)
 *  - POST bodies are decoded as OBJECTS (`{}` stays `{}`)
 *  - optional shared API token: `?token=` wins over `body.token`
 *  - `Cache-Control: no-store` on every JSON reply
 * `$_GET` values are read from the raw query string (no trimming or
 * empty-to-null by Laravel middleware).
 */
class StockLegacyController
{
    public function __construct(
        private readonly StockCatalog $catalog,
        private readonly StockOrders $orders,
        private readonly StockSnapshot $snapshot,
        private readonly StockCk $ck,
        private readonly StockEntries $entries,
        private readonly StockLog $log,
        private readonly StockTeamScope $scope,
    ) {}

    public function items(Request $r): Response
    {
        return $this->serve($r, 'items',
            fn () => ['products' => $this->catalog->products()],
            fn (string $a, stdClass $b) => match ($a) {
                'addProduct' => $this->catalog->saveProduct($b->productName ?? '', $b->primaryVendor ?? '',
                    $b->backupVendors ?? [], $b->oldProductName ?? '',
                    $b->units ?? null, $b->kategori ?? null, $b->area ?? null,
                    $b->caraBeli ?? null, $b->sumber ?? null,
                    $b->packIsi ?? null, $b->packSatuan ?? null,
                    $b->diOutlet ?? null,
                    $b->satuanDasar ?? null, $b->isi ?? null,
                    $b->aktif ?? null),
                'importProducts' => $this->catalog->importProducts($b->rows ?? null),
                'deleteProduct' => $this->catalog->deleteProduct($b->productName ?? ''),
                default => null,
            });
    }

    public function vendors(Request $r): Response
    {
        return $this->serve($r, 'vendors',
            fn () => ['vendors' => $this->catalog->vendors()],
            fn (string $a, stdClass $b) => match ($a) {
                'addVendor' => $this->catalog->saveVendor($b->vendorName ?? '', $b->vendorPhone ?? '', $b->oldVendorName ?? '',
                    $b->perluJadwalJemput ?? null, $b->tutupHari ?? null,
                    $b->penerima ?? null, $b->bank ?? null, $b->norek ?? null),
                'importVendors' => $this->catalog->importVendors($b->rows ?? null),
                'deleteVendor' => $this->catalog->deleteVendor($b->vendorName ?? ''),
                default => null,
            });
    }

    public function orders(Request $r): Response
    {
        return $this->serve($r, 'orders',
            function (array $get) {
                $aksi = $get['action'] ?? '';

                return match ($aksi) {
                    'stats' => $this->orders->stats(),
                    'batches' => $this->orders->batches(trim(StockSupport::str($get['tim'] ?? '')), trim(StockSupport::str($get['tgl'] ?? ''))),
                    default => $this->orders->all(), // a bare array, like Apps Script
                };
            },
            fn (string $a, stdClass $b) => match ($a) {
                'batchOrder' => $this->orders->batchOrder($b->orders ?? [], $b),
                'import' => $this->orders->import($b->orders ?? []),
                'archive' => $this->orders->setStatus($b, 'Arsip'),
                'unarchive' => $this->orders->setStatus($b, 'Aktif'),
                'updateKedatangan' => $this->orders->updateKedatangan($b->updates ?? []),
                'updateTglJemput' => $this->orders->updateTglJemput($b->updates ?? []),
                'updateOrderQty' => $this->orders->updateQty($b->rowIndex ?? 0, $b->newQty ?? null),
                'deleteRow' => $this->orders->deleteRow($b->rowIndex ?? 0),
                default => null,
            });
    }

    /** stock.php: POST has no action — it always replaces the snapshot. */
    public function stock(Request $r): Response
    {
        return $this->serve($r, 'stock',
            fn () => $this->snapshot->read(),
            fn (string $a, stdClass $b) => $this->snapshot->replace($b->stock ?? null, $b->as_of ?? ''),
            actionless: true);
    }

    /** ck.php: `saldo` ignores the date range (a balance is "now"); only `mutasi` is filtered. */
    public function ck(Request $r): Response
    {
        return $this->serve($r, 'ck',
            fn (array $q) => ['saldo' => $this->ck->balance(), 'mutasi' => $this->ck->movements(...self::range($q))],
            fn (string $a, stdClass $b) => match ($a) {
                'simpan' => $this->ck->save($b),
                'hapus' => $this->ck->delete($b->id ?? ''),
                'kirim' => $this->ck->send($b),
                default => null,
            });
    }

    public function usage(Request $r): Response
    {
        return $this->serve($r, 'usage',
            fn (array $q) => $this->entries->usageList($this->scope->forRequest(null), ...self::range($q)),
            fn (string $a, stdClass $b) => match ($a) {
                'simpan' => $this->entries->usageSave($b),
                'status' => $this->entries->usageStatus($b->id ?? '', $b->status ?? ''),
                'hapus' => $this->entries->usageDelete($b->id ?? ''),
                default => null,
            });
    }

    public function waste(Request $r): Response
    {
        return $this->serve($r, 'waste',
            fn (array $q) => ($q['action'] ?? '') === 'foto'
                ? $this->entries->wastePhoto(trim(StockSupport::str($q['id'] ?? '')))
                : $this->entries->wasteList($this->scope->forRequest(null), ...self::range($q)),
            fn (string $a, stdClass $b) => match ($a) {
                'simpan' => $this->entries->wasteSave($b),
                'hapus' => $this->entries->wasteDelete($b->id ?? ''),
                default => null,
            });
    }

    public function serah(Request $r): Response
    {
        return $this->serve($r, 'serah',
            fn (array $q) => ($q['action'] ?? '') === 'foto'
                ? $this->entries->handoverPhoto(trim(StockSupport::str($q['id'] ?? '')))
                : $this->entries->handoverList($this->scope->forRequest(null), ...self::range($q)),
            fn (string $a, stdClass $b) => match ($a) {
                'simpan' => $this->entries->handoverSave($b),
                'hapus' => $this->entries->handoverDelete($b->id ?? ''),
                default => null,
            });
    }

    public function opname(Request $r): Response
    {
        return $this->serve($r, 'opname',
            fn (array $q) => $this->entries->opnameList(...self::range($q)),
            fn (string $a, stdClass $b) => match ($a) {
                'simpan' => $this->entries->opnameSave($b),
                'hapus' => $this->entries->opnameDelete($b->id ?? ''),
                default => null,
            });
    }

    public function log(Request $r): Response
    {
        return $this->serve($r, 'log',
            fn (array $q) => $this->log->read(...self::range($q),
                modul: trim(StockSupport::str($q['modul'] ?? '')),
                cari: trim(StockSupport::str($q['q'] ?? '')),
                limit: self::int($q['limit'] ?? 300)),
            fn (string $a, stdClass $b) => match ($a) {
                'catat' => $this->log->write($b->entri ?? []),
                default => null,
            });
    }

    /** pur_filter_tanggal()'s ?dari=&ke=, trimmed. */
    private static function range(array $q): array
    {
        return [trim(StockSupport::str($q['dari'] ?? '')), trim(StockSupport::str($q['ke'] ?? ''))];
    }

    /** PHP's (int) cast of a query value (an array counts as 0 / 1). */
    private static function int(mixed $v): int
    {
        return is_array($v) ? (int) (bool) $v : (int) $v;
    }

    // ─────────────────────────────── plumbing ──

    /**
     * @param  Closure(array $get): mixed  $get
     * @param  Closure(string $action, stdClass $body): mixed  $post  null = unknown action
     */
    private function serve(Request $r, string $file, Closure $get, Closure $post, bool $actionless = false): Response
    {
        parse_str((string) $r->server('QUERY_STRING', ''), $q);
        $method = strtoupper($r->getMethod());

        if ($method === 'GET' && ($q['action'] ?? '') === 'ping') {
            return self::json(['status' => 'success', 'ok' => true, 'time' => gmdate('c'),
                'env' => Modules::envLabel(), 'db' => Modules::databaseName('stock')]);
        }

        try {
            if ($method === 'GET') {
                if ($bad = self::checkToken($q, null)) {
                    return $bad;
                }

                return self::json($get($q));
            }
            if ($method === 'POST') {
                $b = json_decode((string) $r->getContent());
                if (! $b instanceof stdClass) {
                    return self::error('body bukan JSON', 400);
                }
                if ($bad = self::checkToken($q, $b)) {
                    return $bad;
                }
                $a = $actionless ? '' : StockSupport::str($b->action ?? '');
                $out = $post($a, $b);

                return $out === null ? self::error('action tidak dikenal: '.$a, 400) : self::json($out);
            }

            return self::error('metode tidak didukung', 405);
        } catch (Throwable $e) {
            Log::error("[stock/$file] ".$e->getMessage());
            report($e);

            return self::error('kesalahan server', 500);
        }
    }

    /** pur_cek_token(): empty configured token = open. */
    private static function checkToken(array $q, ?stdClass $b): ?Response
    {
        $expected = (string) config('laksamana.legacy_api_token', '');
        if ($expected === '') {
            return null;
        }
        $t = $q['token'] ?? ($b && isset($b->token) ? $b->token : '');
        if (! is_string($t) || ! hash_equals($expected, $t)) {
            return self::error('token salah', 403);
        }

        return null;
    }

    public static function json(mixed $payload, int $status = 200): Response
    {
        return new Response(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function error(string $message, int $status): Response
    {
        return self::json(['status' => 'error', 'message' => $message], $status);
    }
}
