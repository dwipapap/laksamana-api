<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisRosterService;
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
}
