<?php

namespace App\Modules\Stock\Http\V1;

use App\Modules\Stock\Services\StockConflict;
use App\Modules\Stock\Services\StockHpp;
use App\Modules\Stock\Services\StockHppRecords;
use App\Modules\Stock\Services\StockSupport;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use stdClass;

/**
 * /api/v1/stock/hpp — the HPP Panel: Bahan & Harga (+ Barang Floor), Daftar Resep,
 * Pemakaian & Selisih, Pengaturan (see docs/api/stock.md). Dashboard and
 * Kalkulator are computed by the client from these reads, as the old screen does.
 * `by` (updated_by) is always the acting user.
 */
class HppController
{
    public function __construct(
        private readonly StockHppRecords $records,
        private readonly StockHpp $hpp,
    ) {}

    private static function by(Request $r): string
    {
        return (string) $r->user()->name;
    }

    // ─────────────────────────── ingredients ──

    public function ingredientIndex(): JsonResponse
    {
        return self::list($this->records->ingredients(), 'nama');
    }

    public function ingredientShow(string $nama): JsonResponse
    {
        return self::one($this->records->ingredient($nama));
    }

    public function ingredientStore(Request $r): JsonResponse
    {
        return $this->withBody($r, fn (array $b) => $this->write(fn () => $this->records->saveIngredient(null, $b, null, self::by($r)), 201), false);
    }

    public function ingredientUpdate(Request $r, string $nama): JsonResponse
    {
        return $this->withBody($r, fn (array $b, string $v) => $this->write(fn () => $this->records->saveIngredient($nama, $b, $v, self::by($r))));
    }

    public function ingredientDestroy(Request $r, string $nama): JsonResponse
    {
        return $this->withVersion($r, fn (string $v) => $this->write(function () use ($nama, $v) {
            $this->records->deleteIngredient($nama, $v);

            return null;
        }));
    }

    /** Body {from, into}; If-Match = the version of `from`. */
    public function ingredientMerge(Request $r): JsonResponse
    {
        return $this->withBody($r, function (array $b, string $v) {
            try {
                $res = $this->records->mergeIngredient(StockSupport::str($b['from'] ?? ''), StockSupport::str($b['into'] ?? ''), $v);
            } catch (StockConflict $e) {
                return self::conflict($e);
            }

            return ApiResponse::ok($res['into']['record'] ?? null, ['version' => $res['into']['version'] ?? null, 'recipes' => $res['recipes']]);
        });
    }

    public function ingredientImport(Request $r): JsonResponse
    {
        return $this->rows($r, fn ($rows) => $this->hpp->importIngredients($rows, self::by($r)));
    }

    public function pullProducts(): JsonResponse
    {
        return ApiResponse::ok(['pulled' => $this->hpp->pullProducts()['ditarik']]);
    }

    public function removeShadows(): JsonResponse
    {
        $res = $this->hpp->deleteShadows();

        return ApiResponse::ok(['deleted' => $res['dihapus'], 'names' => $res['nama']]);
    }

    public function alignNames(Request $r): JsonResponse
    {
        $res = $this->hpp->alignNames(self::by($r));

        return ApiResponse::ok(['renamed' => $res['ganti'], 'cleared' => $res['bersih'], 'conflicts' => $res['bentrok']]);
    }

    // ─────────────────────────── recipes ──

    public function recipeIndex(): JsonResponse
    {
        return self::list($this->records->recipes(), 'id');
    }

    public function recipeShow(string $id): JsonResponse
    {
        return self::one($this->records->recipe($id));
    }

    public function recipeStore(Request $r): JsonResponse
    {
        return $this->withBody($r, fn (array $b) => $this->write(fn () => $this->records->saveRecipe(null, $b, null, self::by($r)), 201), false);
    }

    public function recipeUpdate(Request $r, string $id): JsonResponse
    {
        return $this->withBody($r, fn (array $b, string $v) => $this->write(fn () => $this->records->saveRecipe($id, $b, $v, self::by($r))));
    }

    public function recipeDestroy(Request $r, string $id): JsonResponse
    {
        return $this->withVersion($r, fn (string $v) => $this->write(function () use ($id, $v) {
            $this->records->deleteRecipe($id, $v);

            return null;
        }));
    }

    public function recipeImport(Request $r): JsonResponse
    {
        return $this->rows($r, fn ($rows) => $this->hpp->importRecipes($rows, self::by($r)));
    }

    // ─────────────────────────── usage & settings ──

    public function monthShow(string $bulan): JsonResponse
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            return ApiResponse::error('validation_failed', 'The month must be YYYY-MM.', 422);
        }

        return self::one($this->records->month($bulan));
    }

    /** Body {baris?:[{bahan, sa, beli, resep, spoil, team, rnd, comp, opname}], penjualan?, catatan?}. */
    public function monthUpdate(Request $r, string $bulan): JsonResponse
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            return ApiResponse::error('validation_failed', 'The month must be YYYY-MM.', 422);
        }

        return $this->withBody($r, fn (array $b, string $v) => $this->write(fn () => $this->records->saveMonth($bulan, $b, $v, self::by($r))));
    }

    public function settingsShow(): JsonResponse
    {
        return self::one($this->records->settings());
    }

    public function settingsUpdate(Request $r): JsonResponse
    {
        return $this->withBody($r, fn (array $b, string $v) => $this->write(fn () => $this->records->saveSettings($b, $v)));
    }

    /**
     * The one-time move from Excel {bahan:[…], resep:[…], replace?}. Refused (409)
     * when HPP already holds data unless `replace` (which wipes both tables).
     * Unlike legacy, all-or-nothing: a bad row rolls the whole import back.
     */
    public function import(Request $r): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass) {
            return self::badBody();
        }
        try {
            $res = StockSupport::db()->transaction(fn () => $this->hpp->import($b, self::by($r), ! empty($b->replace)));
        } catch (RuntimeException $e) {
            return ApiResponse::error('validation_failed', $e->getMessage(), 422);
        }
        if ($res['status'] === 'error') {
            return ApiResponse::error('hpp_not_empty', $res['message'], 409);
        }

        return ApiResponse::ok(['ingredients' => $res['bahan'], 'recipes' => $res['resep']], [], 201);
    }

    // ─────────────────────────── helpers ──

    private static function list(array $rows, string $key): JsonResponse
    {
        return ApiResponse::ok(array_column($rows, 'record'), [
            'total' => count($rows),
            'versions' => (object) array_combine(array_map(fn ($x) => (string) $x['record'][$key], $rows), array_column($rows, 'version')),
        ]);
    }

    private static function one(?array $row, int $status = 200): JsonResponse
    {
        return $row ? ApiResponse::ok($row['record'], ['version' => $row['version']], $status, ['ETag' => '"'.$row['version'].'"'])
            : ApiResponse::error('not_found', 'Record not found.', 404);
    }

    private function write(Closure $fn, int $status = 200): JsonResponse
    {
        try {
            $res = $fn();
        } catch (StockConflict $e) {
            return self::conflict($e);
        }
        if ($res === null) {
            return ApiResponse::ok(['deleted' => true]);
        }
        if (isset($res['row'])) { // {row, report}
            return ApiResponse::ok($res['row']['record'], ['version' => $res['row']['version']] + ($res['report'] ? ['report' => $res['report']] : []),
                $status, ['ETag' => '"'.$res['row']['version'].'"']);
        }

        return self::one($res, $status);
    }

    /** Parse the JSON object body; with $needVersion the If-Match (or ?version=) too. */
    private function withBody(Request $r, Closure $fn, bool $needVersion = true): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass) {
            return self::badBody();
        }
        if (! $needVersion) {
            return $fn((array) $b);
        }

        return $this->withVersion($r, fn (string $v) => $fn((array) $b, $v));
    }

    private function withVersion(Request $r, Closure $fn): JsonResponse
    {
        $h = $r->header('If-Match');
        $v = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');
        if (! is_string($v) || $v === '') {
            return ApiResponse::error('version_required', 'Send the version you edited as If-Match (or ?version=).', 428);
        }

        return $fn($v);
    }

    private function rows(Request $r, Closure $fn): JsonResponse
    {
        $b = json_decode((string) $r->getContent());
        if (! $b instanceof stdClass || ! isset($b->rows) || ! is_array($b->rows)) {
            return ApiResponse::error('validation_failed', 'Send {"rows": [...]}.', 422);
        }
        $res = $fn($b->rows);

        return ApiResponse::ok(array_diff_key($res, ['status' => 1, 'saved' => 1]));
    }

    private static function conflict(StockConflict $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'not_found' => ApiResponse::error('not_found', 'Record not found.', 404),
            'exists' => ApiResponse::error('already_exists', 'A record with this name or id already exists.', 409),
            'invalid' => ApiResponse::error('validation_failed', (string) $e->current, 422),
            default => ApiResponse::error('version_conflict', 'The record was changed elsewhere. Reload it and apply your change again.', 409,
                ['current' => $e->current]),
        };
    }

    private static function badBody(): JsonResponse
    {
        return ApiResponse::error('validation_failed', 'The body must be a JSON object.', 422);
    }
}
