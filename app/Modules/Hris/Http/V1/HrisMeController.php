<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisPerhatianService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — the crew surface: punch-screen bootstrap, punching in/out,
 * and punch history. The subject is always the token holder; history is
 * scoped by Lingkup (sendiri/divisi/semua) on the server.
 */
final class HrisMeController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisAttendanceService $hadir,
        private readonly HrisLingkup $lingkup,
        private readonly HrisPerhatianService $perhatian,
    ) {
        parent::__construct($access);
    }

    /** Punch-screen bootstrap: shift, today's punches, locations, server time. */
    public function today(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return ApiResponse::ok($this->hadir->meToday($me)
            + ['perhatian' => $this->perhatian->untuk($me)]
            + ['lingkup' => $this->lingkup->lingkup($me)]);
    }

    /** Punch in or out. */
    public function punch(Request $r): JsonResponse
    {
        $d = $r->validate([
            'arah' => ['required', 'string', 'max:8'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'akurasi' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'descriptor' => ['nullable', 'array', 'min:32', 'max:1024'],
            'model' => ['nullable', 'string', 'max:64'],
            'foto' => ['nullable', 'string', 'max:7000000'],
            'alasan' => ['nullable', 'string', 'max:255'],
        ]);
        $me = $this->me($r);
        try {
            $out = $this->hadir->recordPunch($me, $d);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }

        // A repeated MASUK answers 200: tapping twice on a slow signal is
        // not an error (and not a creation either).
        return ApiResponse::ok($out, [], ! empty($out['duplikat']) ? 200 : 201);
    }

    /** Punch history, scoped by Lingkup. Non-HR/non-heads are forced to their own rows. */
    public function punches(Request $r): JsonResponse
    {
        $d = $r->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'user' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $req = self::s($d['user'] ?? '');
            $scope = $this->lingkup->scopeUsers($me, $req === '' ? null : $req);

            return $this->hadir->punches($scope, $d['from'], $d['to']);
        });
    }

    /** One punch's audit photo: self, HR, or the head of its division. */
    public function punchPhoto(Request $r, string $id)
    {
        $me = $this->me($r);
        $row = HrisKetukan::query()->find($id);
        if (! $row) {
            return ApiResponse::error('not_found', 'Ketukan tidak ada.', 404);
        }
        try {
            $scope = $this->lingkup->scopeUsers($me, null);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
        if ($scope !== null && ! in_array($row->user_id, $scope, true)) {
            return ApiResponse::error('forbidden', 'Tidak berhak melihat foto ini.', 403);
        }
        $file = $this->hadir->punchPhotoPath($id);
        if ($file === null) {
            return ApiResponse::error('not_found', 'Foto tidak ada.', 404);
        }

        return response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }
}
