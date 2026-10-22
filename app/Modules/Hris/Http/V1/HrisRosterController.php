<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisRosterEventService;
use App\Modules\Hris\Services\HrisRosterService;
use App\Modules\Hris\Services\HrisUbahShiftService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris/roster and /hris/me/jadwal (J6–J7, ADR-0012). HR plans every
 * shift Divisi; a Kepala Divisi plans the Divisi they head; every crew
 * member reads their own schedule.
 */
final class HrisRosterController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisLingkup $lingkup,
        private readonly HrisRosterService $roster,
        private readonly HrisRosterEventService $riwayat,
        private readonly HrisUbahShiftService $ubah,
    ) {
        parent::__construct($access);
    }

    /** @return array<int,string>|null shift Divisi the caller plans; null = HR (all) */
    private function bolehDivisi(array $me): ?array
    {
        if ($this->lingkup->isHr($me)) {
            return null;
        }
        $divs = $this->lingkup->headDivisi($me);
        if ($divs === []) {
            throw new HrisError('forbidden', 'Roster hanya untuk HR dan Kepala Divisi.', 403);
        }

        return $divs;
    }

    private function assertDivisi(?array $boleh, string $divisi): void
    {
        if ($boleh !== null && ! in_array($divisi, $boleh, true)) {
            throw new HrisError('forbidden', 'Roster Divisi ini hanya untuk HR dan Kepala Divisinya.', 403);
        }
    }

    public function index(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['bulan' => ['required', 'string', 'size:7'], 'divisi' => ['required', 'string', 'max:32']]);

        return $this->run(function () use ($me, $d) {
            $boleh = $this->bolehDivisi($me);
            $this->assertDivisi($boleh, $d['divisi']);

            return $this->roster->bulan($d['bulan'], $d['divisi']) + ['bolehDivisi' => $boleh];
        });
    }

    public function simpan(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['isi' => ['present', 'array', 'max:2000'], 'hapus' => ['present', 'array', 'max:2000']]);

        return $this->run(fn () => $this->roster->simpan($this->bolehDivisi($me), $d['isi'], $d['hapus'], self::s($me['name'])));
    }

    public function rekap(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['bulan' => ['required', 'string', 'size:7'], 'divisi' => ['required', 'string', 'max:32']]);

        return $this->run(function () use ($me, $d) {
            $this->assertDivisi($this->bolehDivisi($me), $d['divisi']);

            return $this->roster->rekap($d['bulan'], $d['divisi']);
        });
    }

    public function aturan(Request $r): JsonResponse
    {
        return $this->run(fn () => $this->roster->aturan());
    }

    public function simpanAturan(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['maksBeruntun' => ['sometimes', 'integer'], 'jedaMin' => ['sometimes', 'integer']]);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Mengubah aturan roster');

            return $this->roster->simpanAturan($d, self::s($me['name']));
        });
    }

    public function saya(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $d = $r->validate(['dari' => ['required', 'date_format:Y-m-d'], 'sampai' => ['required', 'date_format:Y-m-d']]);

        return $this->run(fn () => $this->roster->jadwalSaya(self::s($me['id']), $d['dari'], $d['sampai']));
    }

    /**
     * Kelola roster history tab (issue #346): append-only cell events with
     * filters, plus the shift changes waiting for the HR fallback decider.
     * Admin Modul HRIS (superadmin `*` included) only.
     */
    public function riwayat(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tanggal_dari' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_sampai' => ['nullable', 'date_format:Y-m-d'],
            'waktu_dari' => ['nullable', 'integer', 'min:1'],
            'waktu_sampai' => ['nullable', 'integer', 'min:1'],
            'divisi' => ['nullable', 'string', 'max:32'],
            'user' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:120'],
            'sumber' => ['nullable', 'string', 'max:16'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $me = $this->me($r);

        try {
            $uid = self::s($me['id'] ?? '');
            if ($uid === '' || ! $this->access->isModuleAdmin($uid, 'hris')) {
                throw new HrisError('forbidden', 'Riwayat roster hanya untuk Admin Modul HRIS.', 403);
            }
            $hasil = $this->riwayat->riwayat($d['tanggal_dari'] ?? null, $d['tanggal_sampai'] ?? null,
                isset($d['waktu_dari']) ? (int) $d['waktu_dari'] : null,
                isset($d['waktu_sampai']) ? (int) $d['waktu_sampai'] : null,
                $d['divisi'] ?? null, $d['user'] ?? null, $d['q'] ?? null, $d['sumber'] ?? null,
                (int) ($d['page'] ?? 1), (int) ($d['per_page'] ?? 20));

            return ApiResponse::ok(['event' => $hasil['data'], 'menunggu_hr' => $this->ubah->antreanHr($me)], $hasil['meta']);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }
}
