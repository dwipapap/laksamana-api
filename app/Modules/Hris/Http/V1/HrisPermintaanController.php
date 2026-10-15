<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisPermintaanTenaga;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisRekrutmenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — manpower requests (R1): Kepala Divisi files for their
 * division (HR may file too), HR decides first, Owner (superadmin) decides
 * second. Approval opens a vacancy.
 */
final class HrisPermintaanController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisRekrutmenService $rekrutmen,
        private readonly HrisLingkup $lingkup,
    ) {
        parent::__construct($access);
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'divisi' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:16'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            if ($this->lingkup->isHr($me) || $this->access->isSuperadmin(self::s($me['id']))) {
                $div = self::s($d['divisi'] ?? '');

                return $this->rekrutmen->daftarPermintaan($div === '' ? null : [$div], $d['status'] ?? null);
            }
            if ($this->lingkup->isHead($me)) {
                $div = self::s($d['divisi'] ?? '');
                $milik = $this->lingkup->headDivisi($me);
                if ($div !== '' && ! in_array($div, $milik, true)) {
                    throw new HrisError('forbidden', 'Hanya divisi sendiri.', 403);
                }

                return $this->rekrutmen->daftarPermintaan($div === '' ? $milik : [$div], $d['status'] ?? null);
            }
            throw new HrisError('forbidden', 'Permintaan tenaga untuk Kepala Divisi dan HR.', 403);
        });
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'divisi' => ['required', 'string', 'max:64'],
            'posisi' => ['required', 'string', 'max:120'],
            'jumlah' => ['nullable', 'integer', 'min:1', 'max:100'],
            'jenis' => ['nullable', 'string', 'max:16'],
            'alasan' => ['nullable', 'string', 'max:500'],
            'dibutuhkan_tgl' => ['nullable', 'date_format:Y-m-d'],
            'kualifikasi' => ['nullable', 'string', 'max:2000'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->rekrutmen->bentukPermintaan(
            $this->rekrutmen->buatPermintaan($me, $d)), 201);
    }

    public function decide(Request $r, string $id): JsonResponse
    {
        $d = $r->validate([
            'status' => ['required', 'string', 'max:16'],
            'nota' => ['nullable', 'string', 'max:255'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $id, $d) {
            $row = $this->find($id);

            return $this->rekrutmen->bentukPermintaan(
                $this->rekrutmen->putusPermintaan($me, $row, $d['status'], $d['nota'] ?? ''));
        });
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $row = $this->find($id);

            return $this->rekrutmen->bentukPermintaan($this->rekrutmen->batalPermintaan($me, $row));
        });
    }

    private function find(string $id): HrisPermintaanTenaga
    {
        $row = HrisPermintaanTenaga::query()->find($id);
        if (! $row) {
            throw new HrisError('not_found', 'Permintaan tidak ada.', 404);
        }

        return $row;
    }
}
