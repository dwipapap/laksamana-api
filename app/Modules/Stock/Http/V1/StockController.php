<?php

namespace App\Modules\Stock\Http\V1;

use App\Modules\Stock\Services\StockCatalog;
use App\Modules\Stock\Services\StockConflict;
use App\Modules\Stock\Services\StockOrders;
use App\Modules\Stock\Services\StockRecords;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * /api/v1/stock — products, vendors, orders and Stock Today (see docs/api/stock.md).
 *
 * Serves the Ordering Panel (order form, batch join, check-in, Stock Today
 * upload) and the Purchasing Panel (order monitor, archive, pick-up dates,
 * product & vendor databases), plus the product edits HPP makes.
 *
 * Concurrency: every record has a content-hash `version`; writes send it as
 * If-Match (or ?version=), stale => 409 with the current record.
 */
class StockController
{
    public function __construct(
        private readonly StockRecords $records,
        private readonly StockOrders $orders,
        private readonly StockCatalog $catalog,
    ) {}

    // ─────────────────────────── products & vendors ──

    public function catalogIndex(string $kind): JsonResponse
    {
        $rows = $this->records->catalogList($kind);

        return ApiResponse::ok(array_column($rows, 'record'), [
            'total' => count($rows),
            'versions' => (object) array_combine(array_map(fn ($r) => $r['record']['nama'], $rows), array_column($rows, 'version')),
        ]);
    }

    public function catalogShow(string $kind, string $nama): JsonResponse
    {
        $row = $this->records->catalogFind($kind, $nama);

        return $row ? self::withVersion($row['record'], $row['version']) : self::notFound();
    }

    /**
     * One vendor by name for the wide vendor gate (BD, Brankas): the same
     * record as catalogShow. A separate method only because literal routes
     * pass URI params before defaults, while catalogShow takes ($kind, $nama).
     */
    public function vendorShow(string $nama): JsonResponse
    {
        return $this->catalogShow('vendors', $nama);
    }

    public function catalogStore(Request $r, string $kind): JsonResponse
    {
        if (($b = self::body($r)) === null) {
            return self::badBody();
        }

        return $this->catalogWrite(fn () => $this->records->catalogSave($kind, null, $b, null), 201);
    }

    public function catalogUpdate(Request $r, string $nama, string $kind): JsonResponse
    {
        if (($b = self::body($r)) === null) {
            return self::badBody();
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }

        return $this->catalogWrite(fn () => $this->records->catalogSave($kind, $nama, $b, $v));
    }

    private function catalogWrite(\Closure $fn, int $status = 200): JsonResponse
    {
        try {
            $res = $fn();
        } catch (StockConflict $e) {
            return self::conflict($e);
        }
        $meta = ['version' => $res['row']['version']] + ($res['report'] ? ['report' => $res['report']] : []);

        return ApiResponse::ok($res['row']['record'], $meta, $status, ['ETag' => '"'.$res['row']['version'].'"']);
    }

    public function catalogDestroy(Request $r, string $nama, string $kind): JsonResponse
    {
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        try {
            $this->records->catalogDelete($kind, $nama, $v);
        } catch (StockConflict $e) {
            return self::conflict($e);
        }

        return ApiResponse::ok(['deleted' => true]);
    }

    /** Body {rows:[{nama,…}]} — upsert by name, deletes nothing. */
    public function catalogImport(Request $r, string $kind): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass || ! isset($b->rows) || ! is_array($b->rows)) {
            return ApiResponse::error('validation_failed', 'Send {"rows": [...]}.', 422);
        }
        $res = $kind === 'products' ? $this->catalog->importProducts($b->rows) : $this->catalog->importVendors($b->rows);

        return ApiResponse::ok(array_diff_key($res, ['status' => 1]));
    }

    // ─────────────────────────── orders ──

    public function orderIndex(Request $r): JsonResponse
    {
        $rows = $this->records->orderList($r->query());

        return ApiResponse::ok(array_column($rows, 'record'), [
            'total' => count($rows),
            'versions' => (object) array_combine(array_map(fn ($x) => $x['record']->nomorOrder, $rows), array_column($rows, 'version')),
        ]);
    }

    public function orderShow(string $nomor): JsonResponse
    {
        $row = $this->records->orderFind($nomor);

        return $row ? self::withVersion($row['record'], $row['version']) : self::notFound();
    }

    public function batches(Request $r): JsonResponse
    {
        return ApiResponse::ok($this->orders->batches(trim((string) $r->query('tim', '')), trim((string) $r->query('tgl', ''))));
    }

    public function stats(): JsonResponse
    {
        return ApiResponse::ok($this->orders->stats());
    }

    /**
     * Submit an order batch. Body {orders:[{item,qty,unit,note,tglDatang}], batchId?, batchName?, tim?}.
     * `pic` is the acting user's name (never the body).
     */
    public function submitBatch(Request $r): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass || ! isset($b->orders) || ! is_array($b->orders) || ! $b->orders) {
            return ApiResponse::error('validation_failed', 'Send {"orders": [...]} with at least one item.', 422);
        }
        $name = (string) $r->user()->name;
        foreach ($b->orders as $o) {
            if ($o instanceof stdClass) {
                $o->pic = $name;
            }
        }
        $res = $this->orders->batchOrder($b->orders, $b);
        if ($res['status'] === 'error') {
            return ApiResponse::error('batch_not_found', $res['message'], 409);
        }

        return ApiResponse::ok(array_diff_key($res, ['status' => 1]), [], 201);
    }

    public function orderUpdate(Request $r, string $nomor): JsonResponse
    {
        if (($b = self::body($r)) === null) {
            return self::badBody();
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        try {
            $res = $this->records->orderPatch($nomor, $b, $v);
        } catch (StockConflict $e) {
            return self::conflict($e);
        }

        return ApiResponse::ok($res['row']['record'], ['version' => $res['row']['version']] + $res['report'], 200,
            ['ETag' => '"'.$res['row']['version'].'"']);
    }

    public function orderDestroy(Request $r, string $nomor): JsonResponse
    {
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        try {
            $this->records->orderDelete($nomor, $v);
        } catch (StockConflict $e) {
            return self::conflict($e);
        }

        return ApiResponse::ok(['deleted' => true]);
    }

    /** Body {orderIds:[…]} — the Purchasing monitor's bulk archive / restore. */
    public function archive(Request $r, string $to): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass || ! isset($b->orderIds) || ! is_array($b->orderIds) || ! $b->orderIds) {
            return ApiResponse::error('validation_failed', 'Send {"orderIds": [...]}.', 422);
        }
        $res = $this->orders->setStatus((object) ['orderIds' => array_values(array_map('strval', $b->orderIds))], $to === 'archive' ? 'Arsip' : 'Aktif');

        return ApiResponse::ok(['updated' => $res['updated']]);
    }

    /** Migration tool: keeps numbers and statuses, idempotent by nomorOrder. */
    public function import(Request $r): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass || ! isset($b->orders) || ! is_array($b->orders) || ! $b->orders) {
            return ApiResponse::error('validation_failed', 'Send {"orders": [...]}.', 422);
        }

        return ApiResponse::ok(array_diff_key($this->orders->import($b->orders), ['status' => 1]));
    }

    // ─────────────────────────── stock today ──

    public function stockToday(): JsonResponse
    {
        $s = $this->records->stockToday();

        return self::withVersion($s['record'], $s['version']);
    }

    /** Body {as_of, stock:{nama:{stock_now,stock_unit}}} — replaces the whole snapshot; empty refused. */
    public function putStockToday(Request $r): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass || ! isset($b->stock) || ! $b->stock instanceof stdClass) {
            return ApiResponse::error('validation_failed', 'Send {"as_of": "…", "stock": {…}}.', 422);
        }
        if (($v = self::version($r)) === null) {
            return self::versionRequired();
        }
        try {
            $s = $this->records->replaceStockToday($b->stock, $b->as_of ?? '', $v);
        } catch (StockConflict $e) {
            return self::conflict($e);
        }

        return self::withVersion($s['record'], $s['version']);
    }

    // ─────────────────────────── helpers ──

    /** Raw JSON object as an assoc array (no trimming, no empty-to-null). */
    private static function body(Request $r): ?array
    {
        $b = json_decode((string) $r->getContent());

        return $b instanceof stdClass ? (array) $b : null;
    }

    private static function version(Request $r): ?string
    {
        $h = $r->header('If-Match');
        if (is_string($h) && $h !== '') {
            return trim($h, ' "W/');
        }
        $q = $r->query('version');

        return is_string($q) && $q !== '' ? $q : null;
    }

    private static function withVersion(mixed $data, string $version, int $status = 200): JsonResponse
    {
        return ApiResponse::ok($data, ['version' => $version], $status, ['ETag' => '"'.$version.'"']);
    }

    private static function conflict(StockConflict $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'not_found' => self::notFound(),
            'exists' => ApiResponse::error('already_exists', 'A record with this name already exists.', 409),
            'invalid' => ApiResponse::error('validation_failed', (string) $e->current, 422),
            default => ApiResponse::error('version_conflict', 'The record was changed elsewhere. Reload it and apply your change again.', 409,
                ['current' => $e->current]),
        };
    }

    private static function versionRequired(): JsonResponse
    {
        return ApiResponse::error('version_required', 'Send the version you edited as If-Match (or ?version=).', 428);
    }

    private static function notFound(): JsonResponse
    {
        return ApiResponse::error('not_found', 'Record not found.', 404);
    }

    private static function badBody(): JsonResponse
    {
        return ApiResponse::error('validation_failed', 'The body must be a JSON object.', 422);
    }
}
