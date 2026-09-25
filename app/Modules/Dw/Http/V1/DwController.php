<?php

namespace App\Modules\Dw\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Dw\Services\DwService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /api/v1/dw — Daily Worker for new apps. Same rules as the legacy compat
 * route (DwService); the caller is the Sanctum user behind `module:dw`.
 *
 * Cluster (1): overview & schedule reads, the talent pool (workers), head
 * requests (requests) incl. assigning, and shift assignments (assignments)
 * incl. decisions. Attendance, replacement, payment marks and settings arrive
 * with #14. Rule violations surface as 403 `forbidden` with the legacy
 * message; an overlap that the legacy API answers with {saved:false} is a
 * 409 `overlap` here with the conflict in error.details.
 */
class DwController
{
    public function __construct(
        private readonly DwService $dw,
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
            $out = $fn();
            if (is_array($out) && array_key_exists('saved', $out) && $out['saved'] === false) {
                return ApiResponse::error('overlap', 'Shift bertindih dengan jadwal yang sudah ada.', 409, $out);
            }

            return ApiResponse::ok($out, [], $okStatus);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'tidak_berhak:')) {
                return ApiResponse::error('forbidden', trim(substr($msg, 13)), 403);
            }

            return ApiResponse::error('rejected', $msg, 422);
        }
    }

    // ------------------------------------------------------------ reads

    /** One-call bootstrap: setting, talent pool, assignments, requests + roles (same as legacy getAll). */
    public function overview(Request $r): JsonResponse
    {
        $d = $r->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $me = $this->me($r);
        $data = $this->dw->readAll($d['from'] ?? '', $d['to'] ?? '', $this->dw->bolehLihat($me));
        $data['peran'] = $this->dw->peran($me);

        return ApiResponse::ok($data, ['from' => $d['from'] ?? '', 'to' => $d['to'] ?? '']);
    }

    /** Approved shifts in a range (= legacy jadwalDW): {rows, dari, sampai}. */
    public function schedule(Request $r): JsonResponse
    {
        $d = $r->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);

        return $this->run(fn () => $this->dw->scheduleRange($d['from'], $d['to']));
    }

    // ------------------------------------------------------------ workers

    public function workers(Request $r): JsonResponse
    {
        $d = $r->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'divisi' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $me = $this->me($r);

        return ApiResponse::ok($this->dw->listWorkers($d, $this->dw->bolehLihat($me)));
    }

    public function worker(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        $all = $this->dw->listWorkers([], $this->dw->bolehLihat($me));
        foreach ($all as $p) {
            if ((string) $p['id'] === $id) {
                return ApiResponse::ok($p);
            }
        }

        return ApiResponse::error('not_found', 'Worker not found.', 404);
    }

    public function createWorker(Request $r): JsonResponse
    {
        $row = $this->workerRow($r);
        unset($row['id']); // creates always mint a new id
        $me = $this->me($r);

        return $this->run(function () use ($row, $me) {
            $this->dw->assertHrd($me, 'Mengubah data daily worker');

            return $this->dw->savePekerja($row, $me['name']);
        }, 201);
    }

    public function saveWorker(Request $r, string $id): JsonResponse
    {
        $row = $this->workerRow($r);
        $row['id'] = $id;
        $me = $this->me($r);
        if (! $this->dw->pekerjaById($id)) {
            return ApiResponse::error('not_found', 'Worker not found.', 404);
        }

        return $this->run(function () use ($row, $me) {
            $this->dw->assertHrd($me, 'Mengubah data daily worker');

            return $this->dw->savePekerja($row, $me['name']);
        });
    }

    public function deleteWorker(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        if (! $this->dw->pekerjaById($id)) {
            return ApiResponse::error('not_found', 'Worker not found.', 404);
        }

        return $this->run(function () use ($me, $id) {
            $this->dw->assertHrd($me, 'Menghapus daily worker');

            return $this->dw->deletePekerja($id);
        });
    }

    private function workerRow(Request $r): array
    {
        return $r->validate([
            'id' => ['nullable', 'string', 'max:32'],
            'nama' => ['nullable', 'string', 'max:120'],
            'hp' => ['nullable', 'string', 'max:32'],
            'gender' => ['nullable', 'string', 'max:10'],
            'area' => ['nullable', 'string', 'max:80'],
            'bank' => ['nullable', 'string', 'max:120'],
            'bayarJenis' => ['nullable', 'string', 'max:16'],
            'bayarBank' => ['nullable', 'string', 'max:60'],
            'bayarNomor' => ['nullable', 'string', 'max:60'],
            'bayarNama' => ['nullable', 'string', 'max:120'],
            'divisi' => ['nullable'],
            'posisi' => ['nullable'],
            'skill' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:16'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);
    }

    // ------------------------------------------------------------ requests

    public function requests(Request $r): JsonResponse
    {
        $d = $r->validate([
            'divisi' => ['nullable', 'string', 'max:16'],
            'status' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $f = [];
        if (! empty($d['divisi'])) {
            $f['divisi'] = $d['divisi'];
        }
        if (! empty($d['status'])) {
            $f['status'] = explode(',', (string) $d['status']);
        }
        if (! empty($d['from'])) {
            $f['dari'] = $d['from'];
        }
        if (! empty($d['to'])) {
            $f['sampai'] = $d['to'];
        }

        return ApiResponse::ok($this->dw->listRequests($f));
    }

    public function request(string $id): JsonResponse
    {
        $pm = $this->dw->permintaanById($id);

        return $pm ? ApiResponse::ok($this->dw->bentukPermintaan($pm))
            : ApiResponse::error('not_found', 'Request not found.', 404);
    }

    public function createRequest(Request $r): JsonResponse
    {
        $row = $this->requestRow($r);
        unset($row['id']);
        $me = $this->me($r);

        return $this->run(function () use ($row, $me) {
            $div = isset($row['divisi']) && ! is_array($row['divisi']) ? mb_substr(trim((string) $row['divisi']), 0, 16) : '';
            $this->dw->assertMinta($me, $div);

            return $this->dw->savePermintaan($row, $me['name']);
        }, 201);
    }

    public function saveRequest(Request $r, string $id): JsonResponse
    {
        $row = $this->requestRow($r);
        $row['id'] = $id;
        $me = $this->me($r);
        if (! $this->dw->permintaanById($id)) {
            return ApiResponse::error('not_found', 'Request not found.', 404);
        }

        return $this->run(function () use ($row, $me) {
            $div = isset($row['divisi']) && ! is_array($row['divisi']) ? mb_substr(trim((string) $row['divisi']), 0, 16) : '';
            $this->dw->assertMinta($me, $div);

            return $this->dw->savePermintaan($row, $me['name']);
        });
    }

    public function decideRequest(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['status' => ['required', 'in:DISETUJUI,DITOLAK,MENUNGGU,BATAL'], 'nota' => ['nullable', 'string', 'max:255']]);
        $me = $this->me($r);
        $pm = $this->dw->permintaanById($id);
        if (! $pm) {
            return ApiResponse::error('not_found', 'Request not found.', 404);
        }

        return $this->run(function () use ($me, $pm, $id, $d) {
            if (! $this->dw->isHrd($me)) {
                $h = (isset($me['headDivisi']) && is_array($me['headDivisi'])) ? $me['headDivisi'] : [];
                if ($d['status'] !== 'BATAL' || ! in_array($pm['divisi'], $h, true)) {
                    throw new RuntimeException('tidak_berhak: Menyetujui atau menolak permintaan hanya bisa dilakukan HRD.');
                }
            }

            return $this->dw->decidePermintaan($id, $d['status'], $d['nota'] ?? '', $me['name']);
        });
    }

    public function assign(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['dwIds' => ['required', 'array', 'min:1'], 'dwIds.*' => ['string', 'max:32']]);
        $me = $this->me($r);
        if (! $this->dw->permintaanById($id)) {
            return ApiResponse::error('not_found', 'Request not found.', 404);
        }

        return $this->run(function () use ($me, $id, $d) {
            $this->dw->assertHrd($me, 'Menugaskan daily worker');

            return $this->dw->assignDw($id, $d['dwIds'], $me['name']);
        });
    }

    public function deleteRequest(Request $r, string $id): JsonResponse
    {
        $pm = $this->dw->permintaanById($id);
        if (! $pm) {
            return ApiResponse::error('not_found', 'Request not found.', 404);
        }
        $me = $this->me($r);

        return $this->run(function () use ($me, $pm, $id) {
            if (! $this->dw->isHrd($me)) {
                $h = (isset($me['headDivisi']) && is_array($me['headDivisi'])) ? $me['headDivisi'] : [];
                if (! in_array($pm['divisi'], $h, true)) {
                    throw new RuntimeException('tidak_berhak: Permintaan ini bukan milik divisi Anda.');
                }
            }

            return $this->dw->deletePermintaan($id);
        });
    }

    private function requestRow(Request $r): array
    {
        return $r->validate([
            'id' => ['nullable', 'string', 'max:32'],
            'divisi' => ['nullable', 'string', 'max:16'],
            'tgl' => ['nullable', 'date_format:Y-m-d'],
            'm' => ['nullable', 'string', 'max:5'],
            's' => ['nullable', 'string', 'max:5'],
            'posisi' => ['nullable', 'string', 'max:60'],
            'jumlah' => ['nullable', 'integer', 'min:1', 'max:30'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'usulan' => ['nullable', 'array', 'max:30'],
            'usulan.*' => ['string', 'max:32'],
        ]);
    }

    // ------------------------------------------------------------ assignments

    public function assignments(Request $r): JsonResponse
    {
        $d = $r->validate([
            'dwId' => ['nullable', 'string', 'max:32'],
            'divisi' => ['nullable', 'string', 'max:16'],
            'status' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $f = [];
        foreach (['dwId' => 'dwId', 'divisi' => 'divisi'] as $in => $k) {
            if (! empty($d[$in])) {
                $f[$k] = $d[$in];
            }
        }
        if (! empty($d['status'])) {
            $f['status'] = explode(',', (string) $d['status']);
        }
        if (! empty($d['from'])) {
            $f['dari'] = $d['from'];
        }
        if (! empty($d['to'])) {
            $f['sampai'] = $d['to'];
        }

        return ApiResponse::ok($this->dw->listAssignments($f));
    }

    public function assignment(string $id): JsonResponse
    {
        $a = $this->dw->ajuanById($id);

        return $a ? ApiResponse::ok($this->dw->bentukAjuan($a))
            : ApiResponse::error('not_found', 'Assignment not found.', 404);
    }

    /**
     * Creates (or re-submits, when `id` names an existing row — an edit
     * always returns it to MENUNGGU for re-approval). `timpa: true` replaces
     * the conflicting shift after the caller shows the conflict. An overlap
     * without `timpa` is a 409 `overlap`.
     */
    public function createAssignment(Request $r): JsonResponse
    {
        $row = $this->assignmentRow($r);
        $me = $this->me($r);

        return $this->run(function () use ($row, $me) {
            $div = isset($row['divisi']) && ! is_array($row['divisi']) ? mb_substr(trim((string) $row['divisi']), 0, 16) : '';
            $this->dw->assertMinta($me, $div);

            return $this->dw->saveAjuan($row, $me['name']);
        }, 201);
    }

    public function decideAssignment(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['status' => ['required', 'in:DISETUJUI,DITOLAK,MENUNGGU,BATAL'], 'nota' => ['nullable', 'string', 'max:255']]);
        $me = $this->me($r);
        if (! $this->dw->ajuanById($id)) {
            return ApiResponse::error('not_found', 'Assignment not found.', 404);
        }

        return $this->run(function () use ($me, $id, $d) {
            $this->dw->assertHrd($me, 'Memutuskan ajuan daily worker');

            return $this->dw->decideAjuan($id, $d['status'], $d['nota'] ?? '', $me['name']);
        });
    }

    public function decideMany(Request $r): JsonResponse
    {
        $d = $r->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string', 'max:32'],
            'status' => ['required', 'in:DISETUJUI,DITOLAK,MENUNGGU,BATAL'],
            'nota' => ['nullable', 'string', 'max:255'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->dw->assertHrd($me, 'Memutuskan ajuan daily worker');

            return $this->dw->decideBanyak($d['ids'], $d['status'], $d['nota'] ?? '', $me['name']);
        });
    }

    public function deleteAssignment(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);
        if (! $this->dw->ajuanById($id)) {
            return ApiResponse::error('not_found', 'Assignment not found.', 404);
        }

        return $this->run(function () use ($me, $id) {
            $this->dw->assertHrd($me, 'Menghapus ajuan');

            return $this->dw->deleteAjuan($id);
        });
    }

    private function assignmentRow(Request $r): array
    {
        return $r->validate([
            'id' => ['nullable', 'string', 'max:32'],
            'dwId' => ['nullable', 'string', 'max:32'],
            'tgl' => ['nullable', 'date_format:Y-m-d'],
            'm' => ['nullable', 'string', 'max:5'],
            's' => ['nullable', 'string', 'max:5'],
            'divisi' => ['nullable', 'string', 'max:16'],
            'posisi' => ['nullable', 'string', 'max:60'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'timpa' => ['nullable', 'boolean'],
        ]);
    }
}
