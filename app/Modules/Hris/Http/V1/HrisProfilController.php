<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisProfilService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — crew profile and contact change requests with HR approval
 * (issue #344). Crew endpoints are owner-scoped by the token holder; the
 * kelola endpoints require an Admin Modul HRIS (superadmin `*` included).
 * `PUT /api/v1/me/contact` and the other Office apps keep their direct
 * behaviour: this approval flow applies to the HRIS surface only.
 */
final class HrisProfilController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisProfilService $profil,
    ) {
        parent::__construct($access);
    }

    // ------------------------------------------------------------------ crew

    public function profile(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->profil->profil($me));
    }

    public function indexMine(Request $r): JsonResponse
    {
        $d = $r->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $me = $this->me($r);

        try {
            $hasil = $this->profil->riwayatSaya(
                $me, $d['status'] ?? null, (int) ($d['page'] ?? 1), (int) ($d['per_page'] ?? 20));

            return ApiResponse::ok($hasil['data'], $hasil['meta']);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function storeMine(Request $r): JsonResponse
    {
        $d = $r->validate([
            'perubahan' => ['required', 'array', 'min:1', 'max:5'],
            'revisi_profil' => ['required', 'string', 'max:128'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        try {
            $hasil = $this->profil->ajukan($me, $d);
            $dibuat = (bool) ($hasil['dibuat'] ?? true);
            unset($hasil['dibuat']);

            return ApiResponse::ok($hasil, [], $dibuat ? 201 : 200);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function showMine(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->profil->detailSaya($me, $id));
    }

    public function cancelMine(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['berubah' => ['required', 'integer']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->profil->batal($me, $id, $d));
    }

    // ------------------------------------------------------------------ HR

    public function indexKelola(Request $r): JsonResponse
    {
        $d = $r->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'divisi' => ['nullable', 'string', 'max:26'],
            'q' => ['nullable', 'string', 'max:120'],
            'user' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $me = $this->me($r);

        try {
            $hasil = $this->profil->antrean(
                $me, $d['status'] ?? null, $d['divisi'] ?? null, $d['q'] ?? null,
                (int) ($d['page'] ?? 1), (int) ($d['per_page'] ?? 20), $d['user'] ?? null);

            return ApiResponse::ok($hasil['data'], $hasil['meta']);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function showKelola(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(fn () => $this->profil->detailKelola($me, $id));
    }

    public function decideKelola(Request $r, string $id): JsonResponse
    {
        $d = $r->validate([
            'status' => ['required', 'string', 'max:16'],
            'catatan' => ['nullable', 'string', 'max:500'],
            'berubah' => ['required', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->profil->putuskan($me, $id, $d));
    }
}
