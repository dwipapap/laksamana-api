<?php

namespace App\Modules\Finance\Http\V1;

use App\Modules\Finance\Services\TagihanConflict;
use App\Modules\Finance\Services\TagihanRutin;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/finance/tagihan — Tagihan Rutin (see docs/api/finance.md): recurring
 * subscriptions and their payments. Due dates are computed client-side from
 * `mulai` + `siklus`; the server only guards one due date paid once.
 *
 * No DELETE route on purpose: payments are cancelled, tagihan deactivated
 * (TagihanRutin). The acting user's name is always the Bearer user, never the
 * body. Validation errors keep the legacy Indonesian messages.
 */
class TagihanRutinController
{
    public function __construct(private readonly TagihanRutin $svc) {}

    /** Daftar tagihan + pembayaran (tg_baca). */
    public function index(): JsonResponse
    {
        return $this->run(fn () => ApiResponse::ok($this->svc->list()));
    }

    /** Buat tagihan (tg_simpan tanpa id). Body: {nama*, kategori?, nominal?, siklus?, mulai?, metode?, catatan?}. */
    public function store(Request $r): JsonResponse
    {
        return $this->run(fn () => ApiResponse::ok($this->svc->create(self::body($r), self::actor($r)), [], 201));
    }

    /** Ubah tagihan. Body: any of {nama, kategori, nominal, siklus, mulai, metode, catatan}. */
    public function update(Request $r, int $id): JsonResponse
    {
        return $this->run(fn () => ApiResponse::ok($this->svc->update($id, self::body($r), self::actor($r))));
    }

    /** Nonaktifkan (atau aktifkan lagi); tidak pernah hapus. Body: {aktif: bool}. */
    public function setActive(Request $r, int $id): JsonResponse
    {
        return $this->run(fn () => ApiResponse::ok($this->svc->setActive($id, (bool) $r->json('aktif'), self::actor($r))));
    }

    /** Catat pembayaran (tg_bayar). Body: {periode*, tglBayar*, nominal*, catatan?}. */
    public function pay(Request $r, int $id): JsonResponse
    {
        return $this->run(fn () => ApiResponse::ok($this->svc->pay($id, self::body($r), self::actor($r)), [], 201));
    }

    /** Batalkan pembayaran (tg_batal). Body: {alasan*}. */
    public function cancel(Request $r, int $id): JsonResponse
    {
        $body = self::body($r);

        return $this->run(fn () => ApiResponse::ok($this->svc->cancel($id, $body['alasan'] ?? null, self::actor($r))));
    }

    private function run(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (TagihanConflict $e) {
            return match ($e->kind) {
                'unavailable' => ApiResponse::error('tagihan_unavailable', (string) $e->current, 503),
                'not_found' => ApiResponse::error('not_found', is_string($e->current) ? $e->current : 'Not found.', 404),
                default => ApiResponse::error('invalid_request', (string) $e->current, 422),
            };
        }
    }

    private static function actor(Request $r): string
    {
        return (string) $r->user()->name;
    }

    /** The legacy actions accept {data: {...}} or the fields flat; v1 accepts both. */
    private static function body(Request $r): array
    {
        $in = $r->json()->all();

        return isset($in['data']) && is_array($in['data']) ? $in['data'] : $in;
    }
}
