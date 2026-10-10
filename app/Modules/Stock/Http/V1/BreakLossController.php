<?php

namespace App\Modules\Stock\Http\V1;

use App\Modules\Stock\Services\StockBreakLoss;
use App\Modules\Stock\Services\StockConflict;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/stock/breakloss — the Break & Loss Panel (module `breakloss`), see
 * docs/api/stock.md. No DELETE route on purpose: movements are cancelled,
 * items deactivated (StockBreakLoss). The acting user's name (oleh, pic) is
 * always the Bearer user, never the body.
 */
class BreakLossController
{
    public function __construct(private readonly StockBreakLoss $svc) {}

    /** ?dari=&ke= filters only the movements; stock is always the whole history. */
    public function index(Request $r): JsonResponse
    {
        return $this->run(fn () => ApiResponse::ok($this->svc->list(self::q($r, 'dari'), self::q($r, 'ke'))));
    }

    public function itemPhoto(string $id): JsonResponse
    {
        return $this->run(fn () => ($p = $this->svc->photo('item', $id)) ? ApiResponse::ok($p) : self::notFound());
    }

    public function movementPhoto(string $id): JsonResponse
    {
        return $this->run(fn () => ($p = $this->svc->photo('mutasi', $id)) ? ApiResponse::ok($p) : self::notFound());
    }

    public function storeItem(Request $r): JsonResponse
    {
        return $this->run(fn () => ApiResponse::ok(['id' => $this->svc->saveItem(null, $r->json()->all(), self::actor($r))], [], 201));
    }

    public function updateItem(Request $r, string $id): JsonResponse
    {
        return $this->run(fn () => ApiResponse::ok(['id' => $this->svc->saveItem($id, $r->json()->all(), self::actor($r))]));
    }

    /** Body {aktif: bool}. */
    public function setActive(Request $r, string $id): JsonResponse
    {
        return $this->run(function () use ($r, $id) {
            $aktif = (bool) $r->json('aktif');
            $this->svc->setActive($id, $aktif, self::actor($r));

            return ApiResponse::ok(['id' => $id, 'aktif' => $aktif]);
        });
    }

    public function storeMovement(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            $out = $this->svc->record($r->json()->all(), self::actor($r));

            return ApiResponse::ok($out + ['mutasi' => $this->svc->movement($out['id'])], [], 201);
        });
    }

    public function updateMovement(Request $r, string $id): JsonResponse
    {
        return $this->run(function () use ($r, $id) {
            $out = $this->svc->edit($id, $r->json()->all(), self::actor($r));

            return ApiResponse::ok($out + ['mutasi' => $this->svc->movement($id)]);
        });
    }

    /** Body {alasan}. */
    public function cancelMovement(Request $r, string $id): JsonResponse
    {
        return $this->run(function () use ($r, $id) {
            $this->svc->cancel($id, $r->json('alasan'), self::actor($r));

            return ApiResponse::ok(['id' => $id, 'mutasi' => $this->svc->movement($id)]);
        });
    }

    private function run(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (StockConflict $e) {
            return match ($e->getMessage()) {
                'not_found' => self::notFound(is_string($e->current) ? $e->current : null),
                'unavailable' => ApiResponse::error('breakloss_unavailable', (string) $e->current, 503),
                'invalid' => is_array($e->current)
                    ? ApiResponse::error('validation_failed', (string) $e->current['message'], 422, ['kurang' => $e->current['kurang']])
                    : ApiResponse::error('validation_failed', (string) $e->current, 422),
                default => ApiResponse::error('version_conflict', 'The record was changed elsewhere. Reload it and apply your change again.', 409),
            };
        }
    }

    private static function actor(Request $r): string
    {
        return mb_substr((string) $r->user()->name, 0, 120, 'UTF-8');
    }

    private static function q(Request $r, string $k): string
    {
        $v = $r->query($k, '');

        return is_string($v) ? trim($v) : '';
    }

    private static function notFound(?string $msg = null): JsonResponse
    {
        return ApiResponse::error('not_found', $msg ?? 'Record not found.', 404);
    }
}
