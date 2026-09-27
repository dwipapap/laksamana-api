<?php

namespace App\Modules\Kompas\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Kompas\Services\VoidBri;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/kompas/voids and /api/v1/kompas/bri — Catatan Void and QRIS BRI
 * fund matching (see docs/api/kompas.md). Module cashier OR finance; the
 * tax/service percentages need the module ADMIN of either.
 *
 * Nothing is ever deleted: rows are cancelled with a reason. The recorded
 * name is always the session user. Versions: a row's `diubah` (ms) — an edit
 * or a cancel sends it as If-Match (or ?version=); the percentages use their
 * `updated_at`. Matching needs no version: one DP per live row is enforced
 * server-side and each row's decision stands alone (as legacy).
 */
class VoidBriController
{
    public function __construct(
        private readonly VoidBri $vb,
        private readonly OfficeAccess $access,
    ) {}

    // ─────────────────────────── voids ──

    public function voids(Request $r): JsonResponse
    {
        return $this->gate($r) ?? ApiResponse::ok($this->vb->voidList($r->query('from', ''), $r->query('to', '')));
    }

    /** One row, or {tgl, bill, penginput, salah, alasan, service?, tax?, items: [{item, subtotal}]} for a whole bill. */
    public function createVoid(Request $r): JsonResponse
    {
        if ($denied = $this->gate($r)) {
            return $denied;
        }
        $d = $r->json()->all();
        unset($d['id']); // always a new row here; edits go through PUT
        [$name, $id] = $this->actor($r);

        return self::out(is_array($d['items'] ?? null) ? $this->vb->saveVoidMany($d, $name, $id) : $this->vb->saveVoid($d, $name, $id), 201);
    }

    public function updateVoid(Request $r, string $id): JsonResponse
    {
        if ($denied = $this->gate($r) ?? $this->stale($r, 'void_log', $id)) {
            return $denied;
        }
        [$name, $uid] = $this->actor($r);

        return self::out($this->vb->saveVoid(['id' => $id] + $r->json()->all(), $name, $uid));
    }

    /** Body {alasan}. */
    public function cancelVoid(Request $r, string $id): JsonResponse
    {
        if ($denied = $this->gate($r) ?? $this->stale($r, 'void_log', $id)) {
            return $denied;
        }

        return self::out($this->vb->cancelVoid($id, $r->json('alasan', ''), $this->actor($r)[0]));
    }

    public function voidSetting(Request $r): JsonResponse
    {
        if ($denied = $this->gate($r)) {
            return $denied;
        }
        $s = $this->vb->voidSetting();

        return ApiResponse::ok($s, ['version' => (int) $s['diubah']]);
    }

    /** Body {tax, service}, both 0..100. Module admin of cashier or finance; If-Match = the setting's version. */
    public function putVoidSetting(Request $r): JsonResponse
    {
        $uid = (string) $r->user()->getKey();
        if (! $this->access->isModuleAdmin($uid, 'cashier') && ! $this->access->isModuleAdmin($uid, 'finance')) {
            return ApiResponse::error('forbidden', 'Hanya admin modul Cashier atau Finance yang boleh mengubah persen tax & service.', 403);
        }
        $v = self::version($r);
        if ($v === null) {
            return self::versionRequired();
        }
        if ($v !== (int) $this->vb->voidSetting()['diubah']) {
            return ApiResponse::error('version_conflict', 'The percentages were changed by someone else.', 409, ['current' => $this->vb->voidSetting()]);
        }

        return self::out($this->vb->saveVoidSetting($r->json()->all(), $this->actor($r)[0]));
    }

    // ─────────────────────────── BRI ──

    public function bri(Request $r): JsonResponse
    {
        return $this->gate($r) ?? ApiResponse::ok($this->vb->briList($r->query('from', ''), $r->query('to', '')));
    }

    /** Body {baris: [{tgl, jam, nominal, ket, settle, booking}]}: upsert by server-made sidik; matches are never overwritten. */
    public function upload(Request $r): JsonResponse
    {
        return $this->write($r, fn ($n, $id) => $this->vb->upload($r->json()->all(), $n, $id));
    }

    /** Body {tgl, jam?, nominal, ket}: a manual incoming fund that is not a reservation DP. */
    public function addManual(Request $r): JsonResponse
    {
        return $this->write($r, fn ($n, $id) => $this->vb->addManual($r->json()->all(), $n, $id), 201);
    }

    /** Body {id, cara: cocok|bukan|lepas, resId?, dpId?, resNama?, resTgl?, catatan?} or {items: [...]}. */
    public function match(Request $r): JsonResponse
    {
        return $this->write($r, fn ($n) => $this->vb->match($r->json()->all(), $n));
    }

    /** Body {alasan}. */
    public function cancelMutation(Request $r, string $id): JsonResponse
    {
        if ($denied = $this->gate($r) ?? $this->stale($r, 'bri_mutasi', $id)) {
            return $denied;
        }

        return self::out($this->vb->cancelMutation($id, $r->json('alasan', ''), $this->actor($r)[0]));
    }

    /** Body {dpId, resId?, nama?, tgl?, nominal?, alasan}: mark a reservation DP as not a valid BRI fund. */
    public function ignoreDp(Request $r): JsonResponse
    {
        return $this->write($r, fn ($n) => $this->vb->ignoreDp(['pulih' => false] + $r->json()->all(), $n));
    }

    /** Lift the mark. */
    public function unignoreDp(Request $r, string $dpId): JsonResponse
    {
        return $this->write($r, fn ($n) => $this->vb->ignoreDp(['dpId' => $dpId, 'pulih' => true], $n));
    }

    // ─────────────────────────── helpers ──

    private function write(Request $r, \Closure $fn, int $status = 200): JsonResponse
    {
        if ($denied = $this->gate($r)) {
            return $denied;
        }

        return self::out($fn(...$this->actor($r)), $status);
    }

    private function gate(Request $r): ?JsonResponse
    {
        $uid = (string) $r->user()->getKey();

        return $this->access->hasModule($uid, 'cashier') || $this->access->hasModule($uid, 'finance') ? null
            : ApiResponse::error('module_not_granted', 'Needs module cashier or finance.', 403);
    }

    /** 428 without a version, 404 for an unknown row, 409 when the row's `diubah` moved; null = go ahead. */
    private function stale(Request $r, string $table, string $id): ?JsonResponse
    {
        // ponytail: checked just before the write, not under a row lock; an edit racing within ms may slip through
        $v = self::version($r);
        if ($v === null) {
            return self::versionRequired();
        }
        // the row's version on whichever storage the Modul is on (#69)
        $row = $this->vb->rowVersion($table, $id);
        if ($row === null) {
            return ApiResponse::error('not_found', 'Not found.', 404);
        }

        return $row === $v ? null
            : ApiResponse::error('version_conflict', 'The row was changed by someone else. Reload it and apply your change again.', 409, ['version' => $row]);
    }

    /** @return array{0:string,1:string} [name, id] of the session user */
    private function actor(Request $r): array
    {
        $id = (string) $r->user()->getKey();

        return [(string) ($this->access->userById($id)['name'] ?? $id), $id];
    }

    private static function version(Request $r): ?int
    {
        $h = $r->header('If-Match');
        $v = is_string($h) && $h !== '' ? trim($h, ' "W/') : $r->query('version');

        return is_string($v) && ctype_digit($v) ? (int) $v : null;
    }

    private static function versionRequired(): JsonResponse
    {
        return ApiResponse::error('version_required', 'Send the row version (diubah) you edited as If-Match (or ?version=).', 428);
    }

    /** Legacy result → v1: ok → data (without `ok`); failure → 422 with the legacy message and `kurang` / `gagal`. */
    private static function out(array $r, int $status = 200): JsonResponse
    {
        if (empty($r['ok'])) {
            return ApiResponse::error('invalid_request', (string) ($r['error'] ?? 'ditolak'), 422, array_intersect_key($r, ['kurang' => 1, 'gagal' => 1]));
        }
        unset($r['ok']);

        return ApiResponse::ok($r, [], $status);
    }
}
