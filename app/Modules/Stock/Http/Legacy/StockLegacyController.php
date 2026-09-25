<?php

namespace App\Modules\Stock\Http\Legacy;

use App\Modules\Stock\Services\StockCatalog;
use App\Modules\Stock\Services\StockOrders;
use App\Modules\Stock\Services\StockSnapshot;
use App\Modules\Stock\Services\StockSupport;
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
