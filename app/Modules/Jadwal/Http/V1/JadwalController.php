<?php

namespace App\Modules\Jadwal\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Jadwal\Services\HeadDirectory;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/jadwal — shift roster for new apps. Same rules as the legacy
 * compat route (JadwalService); the caller is the Sanctum user.
 * Rule violations surface as 403 `forbidden` with the legacy message.
 */
class JadwalController
{
    public function __construct(
        private readonly JadwalService $jadwal,
        private readonly OfficeAccess $access,
        private readonly HeadDirectory $heads,
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

    public function cells(Request $r): JsonResponse
    {
        $d = $r->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
        $all = $this->jadwal->readAll($d['from'], $d['to']);

        return ApiResponse::ok($all['sel'], ['from' => $d['from'], 'to' => $d['to']]);
    }

    public function saveCells(Request $r): JsonResponse
    {
        $d = $r->validate([
            'cells' => ['array'],
            'cells.*.u' => ['required', 'string'], 'cells.*.d' => ['required', 'date_format:Y-m-d'],
            'cells.*.t' => ['required', 'string', 'max:16'],
            'cells.*.m' => ['nullable', 'string', 'max:5'],
            'cells.*.s' => ['nullable', 'string', 'max:5'],
            'cells.*.n' => ['nullable', 'string', 'max:120'],
            'clear' => ['array'],
            'clear.*.u' => ['required', 'string'], 'clear.*.d' => ['required', 'date_format:Y-m-d'],
        ]);
        $me = $this->me($r);

        return $this->run(fn () => $this->jadwal->saveCells($d['cells'] ?? [], $d['clear'] ?? [], $me['name'], $me));
    }

    public function shifts(Request $r): JsonResponse
    {
        $d = $r->validate(['user' => ['nullable', 'string'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);

        return $this->run(fn () => $this->jadwal->shiftRange((string) ($d['user'] ?? ''), $d['from'], $d['to']));
    }

    public function requests(Request $r): JsonResponse
    {
        $d = $r->validate([
            'mine' => ['nullable', 'boolean'],
            'user' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $me = $this->me($r);
        // `mine` wins over `user`: Jadwal Saya is always about the caller.
        $f = ! empty($d['mine']) ? ['userId' => (string) $me['id']]
            : (! empty($d['user']) ? ['userId' => $d['user']] : []);
        if (! empty($d['status'])) {
            $f['status'] = explode(',', (string) $d['status']);
        }
        if (! empty($d['from'])) {
            $f['dari'] = $d['from'];
        }
        if (! empty($d['to'])) {
            $f['sampai'] = $d['to'];
        }

        return $this->run(fn () => $this->jadwal->listRequests($f));
    }

    public function createRequest(Request $r): JsonResponse
    {
        $row = $r->validate([
            'userId' => ['nullable', 'string'], 'jenis' => ['required', 'string', 'max:16'],
            'dari' => ['required', 'date_format:Y-m-d'], 'sampai' => ['nullable', 'date_format:Y-m-d'],
            'alasan' => ['nullable', 'string', 'max:2000'], 'shift' => ['nullable', 'string', 'max:16'],
            'jamMulai' => ['nullable', 'string'], 'jamSelesai' => ['nullable', 'string'],
        ]);
        $me = $this->me($r);
        if (! JadwalService::isAdmin($me) || empty($row['userId'])) {
            $row['userId'] = $me['id'];
        }

        return $this->run(fn () => $this->jadwal->saveRequest($row, $me['name']), 201);
    }

    public function decide(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['status' => ['required', 'in:MENUNGGU_HRD,DISETUJUI,DITOLAK,MENUNGGU'], 'nota' => ['nullable', 'string', 'max:255']]);
        $me = $this->me($r);
        $a = $this->jadwal->pengajuanById($id);
        if (! $a) {
            return ApiResponse::error('not_found', 'Request not found.', 404);
        }

        return $this->run(function () use ($me, $a, $id, $d) {
            $this->jadwal->assertMayWriteRow($me, (string) $a['user_id']);
            $div = $this->jadwal->divisiUser((string) $a['user_id']);
            $isHead = $this->jadwal->isHead($me, $div) || ! $this->jadwal->divHasHead($div);

            return $this->jadwal->decide($id, $d['status'], $d['nota'] ?? '', $me['name'], JadwalService::isAdmin($me), $isHead);
        });
    }

    public function deleteRequest(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $a = $this->jadwal->pengajuanById($id);
        if (! $a) {
            return ApiResponse::error('not_found', 'Request not found.', 404);
        }

        return $this->run(function () use ($me, $a, $id) {
            if ((string) $a['user_id'] !== (string) $me['id']) {
                $this->jadwal->assertMayWriteRow($me, (string) $a['user_id']);
            }

            return $this->jadwal->deleteRequest($id);
        });
    }

    public function settings(): JsonResponse
    {
        return ApiResponse::ok($this->jadwal->setting());
    }

    /** Wipe all cells and requests (settings kept). Same rules as legacy kosongkanSemua. */
    public function clearAll(Request $r): JsonResponse
    {
        $d = $r->validate(['konfirmasi' => ['required', 'string']]);
        $me = $this->me($r);

        return $this->run(function () use ($d, $me) {
            if (trim($d['konfirmasi']) !== 'HAPUS SEMUA') {
                throw new RuntimeException('Konfirmasi tidak cocok — pengosongan dibatalkan.');
            }

            return $this->jadwal->clearAll($me['name']);
        });
    }

    /** Office roster with each User's Divisi attached. */
    public function roster(): JsonResponse
    {
        return $this->run(fn () => $this->jadwal->rosterWithDivisi());
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $data = $r->json()->all();

        return $this->run(fn () => $this->jadwal->saveSetting($data, $this->me($r)['name']));
    }

    public function heads(): JsonResponse
    {
        return ApiResponse::ok((object) $this->heads->compute());
    }
}
