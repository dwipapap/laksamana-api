<?php

namespace App\Modules\Absensi\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Absensi\Services\AbsensiService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/absensi — attendance for new apps. Same rules as the legacy compat
 * route (AbsensiService); the caller is the Sanctum user behind
 * `module:absensi`.
 *
 * Covers every screen of HALAMAN in absensi/index.html (the PWA):
 * Absen (context + punch), Riwayat (recap), Antrean (queue + decisions, HR),
 * Wajah (faces: crew enrol their own, HR anyone), Atur (locations + settings,
 * HR). Late, overtime, early-leave and duration are computed on read, never
 * stored — see AbsensiService::hitungHari().
 *
 * Rule violations surface as 403 `forbidden` with the legacy message; other
 * rejections (bad direction, missing reason, unknown decision, bad face
 * data, bad dates) are 422 `rejected` with the legacy message. A repeated
 * MASUK answers 200 `{duplikat:true}` like the old backend — tapping twice
 * on a slow signal is not an error.
 */
class AbsensiController
{
    public function __construct(
        private readonly AbsensiService $absensi,
        private readonly OfficeAccess $access,
    ) {}

    /** whoami-shaped identity for the service rules. */
    private function me(Request $r): array
    {
        return $this->access->profile($this->access->userById((string) $r->user()->getKey()));
    }

    /** Service rule exceptions -> v1 errors. */
    private function run(callable $fn, int $okStatus = 200): JsonResponse
    {
        try {
            return ApiResponse::ok($fn(), [], $okStatus);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'tidak_berhak:')) {
                return ApiResponse::error('forbidden', trim(substr($msg, 13)), 403);
            }

            return ApiResponse::error('rejected', $msg, 422);
        }
    }

    /** HR gate: module admin, or listed in the setting's hr. */
    private function hr(array $me, string $apa): array
    {
        if (! $this->absensi->isHr($me)) {
            throw new RuntimeException('tidak_berhak: '.$apa.' hanya bisa dilakukan HR/admin modul Absensi.');
        }

        return $me;
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    // ------------------------------------------------------------ session

    /** The login screen (= legacy masuk): account login, then the absensi module check. */
    public function session(Request $r): JsonResponse
    {
        $d = $r->validate([
            'nama' => ['nullable', 'string', 'max:120'],
            'pin' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->run(fn () => ['user' => $this->absensi->loginMasuk($d['nama'] ?? '', $d['pin'] ?? '')]);
    }

    // ------------------------------------------------------------ context

    /** The one-call punch-screen bootstrap (= legacy konteks). */
    public function context(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return ApiResponse::ok($this->absensi->context($me));
    }

    // ------------------------------------------------------------ punches

    /** Punch in or out. The subject is always the token holder. */
    public function punch(Request $r): JsonResponse
    {
        $d = $r->validate([
            'arah' => ['required', 'string', 'max:8'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'akurasi' => ['nullable', 'integer'],
            'descriptor' => ['nullable', 'array', 'size:128'],
            'foto' => ['nullable', 'string'],
            'alasan' => ['nullable', 'string', 'max:255'],
        ]);
        $me = $this->me($r);
        $p = [
            'tipe' => 'USER', 'id' => self::s($me['id']), 'nama' => self::s($me['name']),
            'arah' => $d['arah'] ?? '',
            'lat' => $d['lat'] ?? 0, 'lng' => $d['lng'] ?? 0,
            'akurasi' => $d['akurasi'] ?? 0,
            'descriptor' => $d['descriptor'] ?? null,
            'foto' => $d['foto'] ?? '',
            'alasan' => $d['alasan'] ?? '',
        ];
        try {
            $out = $this->absensi->recordPunch($p);
        } catch (RuntimeException $e) {
            return ApiResponse::error('rejected', $e->getMessage(), 422);
        }

        // A repeated MASUK answers 200: tapping twice on a slow signal is
        // not an error (and not a creation either).
        return ApiResponse::ok($out, [], ! empty($out['duplikat']) ? 200 : 201);
    }

    /** Riwayat: late/overtime/early/duration computed on read. Non-HR are forced to their own rows. */
    public function recap(Request $r): JsonResponse
    {
        $d = $r->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'user' => ['nullable', 'string', 'max:64'],
            'tipe' => ['nullable', 'string', 'max:8'],
        ]);
        $me = $this->me($r);
        $uid = self::s($d['user'] ?? '');
        $tipe = self::s($d['tipe'] ?? '');
        if (! $this->absensi->isHr($me)) {
            $uid = self::s($me['id']);
            $tipe = 'USER';
        }

        return $this->run(fn () => $this->absensi->recap($d['from'], $d['to'], $uid, $tipe));
    }

    /** Antrean: queued punches, longest-waiting first. HR only. */
    public function queue(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->hr($me, 'Melihat antrean pengajuan');

            return $this->absensi->queue();
        });
    }

    /** Decide a queued punch. HR only; only while still MENUNGGU. */
    public function decide(Request $r, string $id): JsonResponse
    {
        $d = $r->validate([
            'status' => ['required', 'string', 'max:16'],
            'nota' => ['nullable', 'string', 'max:255'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $id, $d) {
            $this->hr($me, 'Memutus pengajuan');

            return ['berubah' => $this->absensi->decidePunch(
                $id, $d['status'], $d['nota'] ?? '', self::s($me['name'])) ? 1 : 0];
        });
    }

    // ------------------------------------------------------------ faces

    /** Registered faces, without descriptors. HR only. */
    public function faces(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->hr($me, 'Melihat daftar wajah');

            return $this->absensi->listFaces();
        });
    }

    /** Enrol a face: your own, or anyone's when HR. */
    public function saveFace(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tipe' => ['nullable', 'string', 'max:8'],
            'id' => ['required', 'string', 'max:64'],
            'nama' => ['nullable', 'string', 'max:120'],
            'descriptor' => ['required', 'array', 'size:128'],
            'foto' => ['nullable', 'string'],
        ]);
        $me = $this->me($r);
        $tipe = strtoupper(self::s($d['tipe'] ?? 'USER'));
        $id = self::s($d['id']);
        if (! ($tipe === 'USER' && $id === self::s($me['id'])) && ! $this->absensi->isHr($me)) {
            return ApiResponse::error('forbidden', 'Hanya HR yang boleh mendaftarkan wajah orang lain.', 403);
        }

        return $this->run(fn () => ['tersimpan' => $this->absensi->saveFace(
            $tipe, $id, $d['nama'] ?? '', $d['descriptor'], $d['foto'] ?? '', self::s($me['name']))], 201);
    }

    /** Delete a face. HR only. */
    public function deleteFace(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tipe' => ['nullable', 'string', 'max:8'],
            'id' => ['required', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->hr($me, 'Menghapus wajah');

            return ['hapus' => $this->absensi->deleteFace($d['tipe'] ?? 'USER', $d['id'])];
        });
    }

    // ------------------------------------------------------------ locations

    /** Allowed clock-in locations. */
    public function locations(Request $r): JsonResponse
    {
        return ApiResponse::ok($this->absensi->locations(false));
    }

    /** Create or replace a location (radius clamped to 30–2000 m). HR only. */
    public function saveLocation(Request $r): JsonResponse
    {
        $d = $r->validate([
            'id' => ['nullable', 'string', 'max:32'],
            'nama' => ['required', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'radius' => ['nullable', 'integer'],
            'aktif' => ['nullable', 'boolean'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->hr($me, 'Menyimpan lokasi');

            return ['id' => $this->absensi->saveLocation($d, self::s($me['name']))];
        }, 201);
    }

    /** Hard-delete a location. HR only. */
    public function deleteLocation(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->hr($me, 'Menghapus lokasi');

            return ['hapus' => $this->absensi->deleteLocation($id)];
        });
    }

    // ------------------------------------------------------------ settings

    public function settings(Request $r): JsonResponse
    {
        return ApiResponse::ok($this->absensi->setting());
    }

    /** Save the single settings blob. HR only. */
    public function saveSettings(Request $r): JsonResponse
    {
        $d = $r->validate(['data' => ['required', 'array']]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->hr($me, 'Menyimpan pengaturan');

            return $this->absensi->saveSetting($d['data'], self::s($me['name']));
        });
    }
}
