<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisPengajuan;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisRequestService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — CUTI/PH/IZIN requests (L1). Crew file their own; Kepala
 * Divisi decides first; HRD decides second and writes the Jadwal cells.
 * Reads and cancels are scoped by Lingkup.
 */
final class HrisRequestController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisRequestService $pengajuan,
        private readonly HrisLingkup $lingkup,
    ) {
        parent::__construct($access);
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'user' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:16'],
            'jenis' => ['nullable', 'string', 'max:8'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $req = self::s($d['user'] ?? '');
            $scope = $this->lingkup->scopeUsers($me, $req === '' ? null : $req);

            return $this->pengajuan->list($scope, $d['status'] ?? null, $d['jenis'] ?? null);
        });
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'jenis' => ['required', 'string', 'max:8'],
            'dari' => ['required', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
            'alasan' => ['required', 'string', 'max:500'],
            'lampiran' => ['nullable', 'string', 'max:14000000'],
            'lampiran_nama' => ['nullable', 'string', 'max:180'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->pengajuan->shape($this->pengajuan->create($me, $d)), 201);
    }

    public function show(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $row = $this->find($id);
            $this->lingkup->assertCanSee($me, $row->user_id);

            return $this->pengajuan->shape($row);
        });
    }

    public function lampiran(Request $r, string $id)
    {
        $me = $this->me($r);
        $row = HrisPengajuan::query()->find($id);
        if (! $row) {
            return ApiResponse::error('not_found', 'Pengajuan tidak ada.', 404);
        }
        try {
            $this->lingkup->assertCanSee($me, $row->user_id);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
        $f = $this->pengajuan->lampiranPath($id);
        if ($f === null) {
            return ApiResponse::error('not_found', 'Lampiran tidak ada.', 404);
        }

        return response()->download($f['file'], $f['nama'], [
            'Content-Type' => $f['mime'], 'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Cancel (requester while waiting; HR also after approval, with refund). */
    public function destroy(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $row = $this->find($id);
            $this->lingkup->assertCanSee($me, $row->user_id);

            return $this->pengajuan->shape($this->pengajuan->cancel($me, $row));
        });
    }

    /** Decide (head step, then HRD step). */
    public function decide(Request $r, string $id): JsonResponse
    {
        $d = $r->validate([
            'status' => ['required', 'string', 'max:16'],
            'nota' => ['nullable', 'string', 'max:255'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $id, $d) {
            $row = $this->find($id);
            $hasil = $this->pengajuan->shape(
                $this->pengajuan->decide($me, $row, $d['status'], $d['nota'] ?? ''));

            return $hasil;
        });
    }

    private function find(string $id): HrisPengajuan
    {
        $row = HrisPengajuan::query()->find($id);
        if (! $row) {
            throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        }

        return $row;
    }
}
