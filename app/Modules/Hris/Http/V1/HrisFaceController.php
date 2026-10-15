<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — face enrolment (A3). Self-service at first login, active at
 * once, from the live camera only. Re-enrolment only after an HR reset.
 * A face matching someone else's is rejected (409 wajah_milik_orang_lain)
 * and reported to HR through the audit trail.
 *
 * Enrolment photos are biometric personal data (UU PDP): stored private,
 * streamed to HR only, never in a list payload, never logged.
 */
final class HrisFaceController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisAttendanceService $hadir,
        private readonly HrisLingkup $lingkup,
    ) {
        parent::__construct($access);
    }

    /** Enrol your own face. */
    public function enrolMe(Request $r): JsonResponse
    {
        $d = $r->validate([
            'descriptor' => ['required', 'array', 'min:32', 'max:1024'],
            'model' => ['required', 'string', 'max:64'],
            'foto' => ['nullable', 'string', 'max:7000000'],
            'consent' => ['required', 'accepted'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $row = $this->hadir->enrol($me, $d);

            return ['user_id' => $row->user_id, 'model' => $row->model, 'status' => $row->status];
        }, 201);
    }

    /** HR reset: the person may enrol again. */
    public function reset(Request $r, string $userId): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $userId) {
            $this->lingkup->assertHr($me, 'Mereset wajah');
            if (! $this->hadir->resetFace(self::s($me['name']), $userId)) {
                throw new HrisError('not_found', 'Wajah tidak ada.', 404);
            }

            return ['direset' => true];
        });
    }

    /** Enrolled faces, without descriptors. HR only. */
    public function index(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->lingkup->assertHr($me, 'Melihat daftar wajah');

            return $this->hadir->listFaces();
        });
    }

    /** One enrolment photo. HR only. */
    public function photo(Request $r, string $userId)
    {
        $me = $this->me($r);
        try {
            $this->lingkup->assertHr($me, 'Melihat foto wajah');
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
        $file = $this->hadir->facePhotoPath($userId);
        if ($file === null) {
            return ApiResponse::error('not_found', 'Foto tidak ada.', 404);
        }

        return response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }
}
