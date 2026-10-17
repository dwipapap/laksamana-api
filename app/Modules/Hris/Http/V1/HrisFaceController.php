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
use Illuminate\Support\Facades\RateLimiter;

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

    /** Enrol your own face (W2: `descriptors` 1–3 or the old single `descriptor`). */
    public function enrolMe(Request $r): JsonResponse
    {
        $d = $r->validate([
            'descriptor' => ['nullable', 'array', 'min:32', 'max:1024'],
            'descriptors' => ['nullable', 'array', 'min:1', 'max:3'],
            'descriptors.*' => ['array', 'min:32', 'max:1024'],
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

    /**
     * Pre-send face check (A19, W5): {terdaftar, cocok}, no score served.
     * Every check audits `wajah_dicek` {skor, cocok, model} (no photo, no
     * descriptor). Rate-limited per user — shared with faces/me/tambah:
     * 10 per 5 minutes → 429 terlalu_sering (never audited).
     * A mismatch only warns on the punch screen; the punch itself still
     * matches server-side (the app can be bypassed).
     */
    public function cekMe(Request $r): JsonResponse
    {
        $d = $r->validate([
            'descriptor' => ['required', 'array', 'min:32', 'max:1024'],
            'model' => ['required', 'string', 'max:64'],
        ]);
        $me = $this->me($r);
        $key = 'hris-cek-wajah:'.self::s($me['id'] ?? '');
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return ApiResponse::error('terlalu_sering',
                'Terlalu sering memeriksa wajah. Coba lagi nanti.', 429,
                ['retryAfter' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 5 * 60);

        return $this->run(fn () => $this->hadir->cekWajah($me, $d));
    }

    /**
     * Complete your own face (W3): one more descriptor while AKTIF and
     * below 3. Shares the cek rate limit. At 3 → 409 wajah_lengkap; a probe
     * outside the threshold → 422 wajah_tidak_cocok; someone else's →
     * 409 wajah_milik_orang_lain (audited for HR); another model → 422.
     */
    public function tambahMe(Request $r): JsonResponse
    {
        $d = $r->validate([
            'descriptor' => ['required', 'array', 'min:32', 'max:1024'],
            'model' => ['required', 'string', 'max:64'],
        ]);
        $me = $this->me($r);
        $key = 'hris-cek-wajah:'.self::s($me['id'] ?? '');
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return ApiResponse::error('terlalu_sering',
                'Terlalu sering memeriksa wajah. Coba lagi nanti.', 429,
                ['retryAfter' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 5 * 60);

        return $this->run(fn () => $this->hadir->tambahWajah($me, $d));
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

    /** Recent face-check log of one person (W5). HR only. */
    public function cekLog(Request $r, string $userId): JsonResponse
    {
        $me = $this->me($r);
        $limit = (int) $r->query('limit', 10);

        return $this->run(function () use ($me, $userId, $limit) {
            $this->lingkup->assertHr($me, 'Melihat log cek wajah');

            return $this->hadir->cekLog($userId, $limit);
        });
    }

    /** Check-score percentiles per model (W6). HR only. */
    public function kalibrasi(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->lingkup->assertHr($me, 'Melihat kalibrasi wajah');

            return $this->hadir->kalibrasi();
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

        return response()->file($file, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }
}
