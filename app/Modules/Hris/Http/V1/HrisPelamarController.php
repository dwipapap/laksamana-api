<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Jobs\ScreeningJob;
use App\Modules\Hris\Models\HrisLowongan;
use App\Modules\Hris\Models\HrisPelamar;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisRekrutmenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — vacancies and applicants (R2–R5). HR-only end to end:
 * applicant contacts stay encrypted and are decrypted only here, CV bytes
 * never leave the server, and PII is redacted before any AI call.
 */
final class HrisPelamarController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisRekrutmenService $rekrutmen,
        private readonly HrisLingkup $lingkup,
    ) {
        parent::__construct($access);
    }

    private function hr(array $me, string $apa): void
    {
        $this->lingkup->assertHr($me, $apa);
    }

    // ---------------------------------------------------------------- vacancies

    public function lowongan(Request $r): JsonResponse
    {
        $d = $r->validate(['tutup' => ['nullable', 'boolean']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->rekrutmen->daftarLowongan(! empty($d['tutup'])));
    }

    public function tutupLowongan(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->hr($me, 'Menutup lowongan');
            $row = HrisLowongan::query()->find($id);
            if (! $row) {
                throw new HrisError('not_found', 'Lowongan tidak ada.', 404);
            }

            return $this->rekrutmen->bentukLowongan($this->rekrutmen->tutupLowongan($me, $row));
        });
    }

    // ---------------------------------------------------------------- applicants

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'lowongan' => ['nullable', 'string', 'max:32'],
            'tahap' => ['nullable', 'string', 'max:16'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->hr($me, 'Melihat pelamar');

            return $this->rekrutmen->daftarPelamar(self::s($d['lowongan'] ?? ''), $d['tahap'] ?? null);
        });
    }

    public function show(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->hr($me, 'Melihat pelamar');

            return $this->rekrutmen->bentukPelamar($this->find($id), true);
        });
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'lowongan_id' => ['required', 'string', 'max:32'],
            'nama' => ['required', 'string', 'max:120'],
            'cv' => ['required', 'string', 'max:14000000'],
            'cv_nama' => ['nullable', 'string', 'max:180'],
            'kontak' => ['nullable', 'array'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->hr($me, 'Mengunggah CV');

            return $this->rekrutmen->bentukPelamar($this->rekrutmen->unggahPelamar($me, $d), true);
        }, 201);
    }

    public function tahap(Request $r, string $id): JsonResponse
    {
        $d = $r->validate([
            'tahap' => ['required', 'string', 'max:16'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $id, $d) {
            $this->hr($me, 'Memindah tahap');

            return $this->rekrutmen->bentukPelamar(
                $this->rekrutmen->pindahTahap($me, $this->find($id), $d['tahap'], $d['catatan'] ?? ''), true);
        });
    }

    /** Screen (advice with reasons); ?async=1 queues it on `database`. */
    public function screen(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['async' => ['nullable', 'boolean']]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $id, $d) {
            $this->hr($me, 'Menapis pelamar');
            $row = $this->find($id);
            if (! empty($d['async'])) {
                ScreeningJob::dispatch($row->id, self::s($me['name']));

                return ['diantrekan' => true];
            }

            return $this->rekrutmen->tapis($row->fresh(), self::s($me['name']));
        });
    }

    public function riwayat(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->hr($me, 'Melihat hasil tapis');

            return $this->rekrutmen->riwayatTapis($this->find($id));
        });
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->hr($me, 'Menghapus pelamar');
            $this->rekrutmen->hapusPelamar($me, $this->find($id));

            return ['hapus' => 1];
        });
    }

    private function find(string $id): HrisPelamar
    {
        $row = HrisPelamar::query()->find($id);
        if (! $row) {
            throw new HrisError('not_found', 'Pelamar tidak ada.', 404);
        }

        return $row;
    }
}
