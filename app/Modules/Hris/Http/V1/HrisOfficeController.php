<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisAuditService;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — HR office surface: attendance locations, attendance
 * settings, and the one-time import of the legacy absensi locations.
 * Everything here is HR-only (module admins of hris or the `hr` id list).
 */
final class HrisOfficeController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisAttendanceService $hadir,
        private readonly HrisLingkup $lingkup,
        private readonly HrisSettingsService $settings,
        private readonly HrisAuditService $audit,
    ) {
        parent::__construct($access);
    }

    // ------------------------------------------------------------ locations

    public function locations(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->hadir->locations(false));
    }

    public function saveLocation(Request $r): JsonResponse
    {        $d = $r->validate([
            'id' => ['nullable', 'string', 'max:32'],
            'nama' => ['required', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'radius' => ['nullable', 'integer'],
            'aktif' => ['nullable', 'boolean'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Menyimpan lokasi');
            $lok = $this->hadir->saveLocation($d);
            $this->audit->record(self::s($me['name']), 'lokasi_disimpan', 'hris_lokasi', $lok->id, []);

            return ['id' => $lok->id];
        }, 201);
    }

    /** PATCH takes the id from the path; the body id (if any) must agree. */
    public function patchLocation(Request $r, string $id): JsonResponse
    {
        $r->merge(['id' => $id]);

        return $this->saveLocation($r);
    }

    public function deleteLocation(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->lingkup->assertHr($me, 'Menghapus lokasi');
            $this->audit->record(self::s($me['name']), 'lokasi_dihapus', 'hris_lokasi', $id, []);

            return ['hapus' => $this->hadir->deleteLocation($id) ? 1 : 0];
        });
    }

    public function importLocations(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->lingkup->assertHr($me, 'Mengimpor lokasi');

            return $this->hadir->importLocations(self::s($me['name']));
        });
    }

    // ------------------------------------------------------------ settings

    public function settings(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->settings->all());
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $d = $r->validate(['data' => ['required', 'array']]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Menyimpan pengaturan');
            $out = $this->settings->save($d['data']);
            $this->audit->record(self::s($me['name']), 'pengaturan_disimpan', 'hris_pengaturan', '',
                ['kunci' => array_keys($d['data'])]);

            return $out;
        });
    }
}
