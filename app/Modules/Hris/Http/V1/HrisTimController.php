<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisRequestService;
use App\Modules\Hris\Services\HrisTimRosterService;
use App\Modules\Hris\Services\HrisUbahShiftService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris/tim — crew surface (issue #346): read-only team roster,
 * one-person one-date shift changes, and the head approval queue.
 * Every screen is server-scoped to the token holder; Kelola stays
 * HR-admin only. The official roster cell changes only on approval.
 */
final class HrisTimController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisTimRosterService $tim,
        private readonly HrisUbahShiftService $ubah,
        private readonly HrisRequestService $pengajuan,
    ) {
        parent::__construct($access);
    }

    public function divisi(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->tim->divisi());
    }

    public function roster(Request $r): JsonResponse
    {
        $d = $r->validate([
            'dari' => ['required', 'date_format:Y-m-d'],
            'sampai' => ['required', 'date_format:Y-m-d'],
            'divisi' => ['nullable', 'string', 'max:32'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->tim->roster(
            self::s($me['id'] ?? ''), $d['dari'], $d['sampai'], $d['divisi'] ?? null));
    }

    public function indexUbah(Request $r): JsonResponse
    {
        $d = $r->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $me = $this->me($r);

        try {
            $hasil = $this->ubah->riwayatSaya(
                $me, $d['status'] ?? null, (int) ($d['page'] ?? 1), (int) ($d['per_page'] ?? 20));

            return ApiResponse::ok($hasil['data'], $hasil['meta']);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function storeUbah(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tanggal_kerja' => ['required', 'date_format:Y-m-d'],
            'shift_baru' => ['required', 'string', 'max:16'],
            'alasan' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        try {
            $hasil = $this->ubah->ajukan($me, $d);
            $dibuat = (bool) ($hasil['dibuat'] ?? true);
            unset($hasil['dibuat']);

            return ApiResponse::ok($hasil, [], $dibuat ? 201 : 200);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function showUbah(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->ubah->detailSaya($me, $id));
    }

    public function showUbahKelola(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->ubah->detailKelola($me, $id));
    }

    public function batalUbah(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['berubah' => ['required', 'integer']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->ubah->batal($me, $id, $d));
    }

    /** Queue the caller may decide: shift changes + head-step leave requests. */
    public function persetujuan(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => [
            'ubah_shift' => $this->ubah->antreanKepala($me),
            'pengajuan' => $this->pengajuan->antreanKepala($me),
        ]);
    }

    public function putuskanUbah(Request $r, string $id): JsonResponse
    {
        $d = $r->validate([
            'status' => ['required', 'string', 'max:16'],
            'catatan' => ['nullable', 'string', 'max:500'],
            'berubah' => ['required', 'integer'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->ubah->putuskan($me, $id, $d));
    }
}
