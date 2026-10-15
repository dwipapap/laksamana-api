<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisScoreService;
use App\Modules\Hris\Services\PunchRules;
use App\Modules\Jadwal\Services\JadwalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — People Score (S1–S3, S5) and superior ratings. Monthly
 * feedback and a promotion basis — never a bonus basis. Snapshots lock per
 * month and keep their formula version; kelengkapan < 60 is not ranked.
 */
final class HrisScoreController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisScoreService $skor,
        private readonly HrisLingkup $lingkup,
        private readonly JadwalService $jadwal,
    ) {
        parent::__construct($access);
    }

    /** Scores for the month, scoped by Lingkup. */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'bulan' => ['required', 'date_format:Y-m'],
            'user' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $req = self::s($d['user'] ?? '');
            $scope = $this->lingkup->scopeUsers($me, $req === '' ? null : $req);

            return $this->skor->list($scope, $d['bulan']);
        });
    }

    /** Compute (or recompute while unlocked) one member — or the whole scope. */
    public function hitung(Request $r): JsonResponse
    {
        $d = $r->validate([
            'bulan' => ['required', 'date_format:Y-m'],
            'user' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $req = self::s($d['user'] ?? '');
            if ($req !== '') {
                $this->lingkup->assertCanSee($me, $req);
                // Crew may recompute their own; heads their division; HR anyone.
                if (! $this->lingkup->isHr($me) && ! $this->lingkup->isHead($me)
                    && $req !== self::s($me['id'])) {
                    throw new HrisError('forbidden', 'Tidak berhak.', 403);
                }

                return [$this->skor->hitung($req, $d['bulan'])];
            }
            $out = [];
            foreach ($this->cakupan($me) as $uid) {
                $out[] = $this->skor->hitung($uid, $d['bulan']);
            }

            return $out;
        });
    }

    /** Lock the month (HR). */
    public function kunci(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $this->lingkup->assertHr($me, 'Mengunci skor');
        $d = $r->validate(['bulan' => ['required', 'date_format:Y-m']]);

        return $this->run(function () use ($me, $d) {
            return $this->skor->kunci($d['bulan'], self::s($me['name']));
        });
    }

    /** Active formula + version history. */
    public function formula(Request $r): JsonResponse
    {
        $d = $r->validate(['bulan' => ['nullable', 'date_format:Y-m']]);
        $me = $this->me($r);

        return $this->run(function () use ($d) {
            $bulan = self::s($d['bulan'] ?? '');
            if ($bulan === '') {
                $bulan = substr(PunchRules::tglWib(PunchRules::ms()), 0, 7);
            }

            return ['aktif' => $this->skor->formulaUntuk($bulan),
                'riwayat' => $this->skor->riwayatFormula()];
        });
    }

    /** New formula version (HR). Locked snapshots keep their old version. */
    public function saveFormula(Request $r): JsonResponse
    {
        $me = $this->me($r);
        $this->lingkup->assertHr($me, 'Mengatur formula');
        $d = $r->validate([
            'komponen' => ['required', 'array'],
            'berlaku_dari' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->run(function () use ($me, $d) {
            return $this->skor->saveFormula($d['komponen'], $d['berlaku_dari'], self::s($me['name']));
        }, 201);
    }

    /** Rate one member 1–5 per aspect (head of the division, or HR). */
    public function nilai(Request $r): JsonResponse
    {
        $d = $r->validate([
            'user' => ['required', 'string', 'max:64'],
            'bulan' => ['required', 'date_format:Y-m'],
            'aspek' => ['required', 'array', 'min:1', 'max:6'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $row = $this->skor->nilaiBawahan($me, self::s($d['user']), $d['bulan'],
                is_array($d['aspek']) ? $d['aspek'] : []);

            return ['user_id' => $row->user_id, 'bulan' => $row->bulan, 'aspek' => $row->aspek];
        });
    }

    /** User ids the caller may compute: self, division, or the roster (HR). */
    private function cakupan(array $me): array
    {
        $scope = $this->lingkup->scopeUsers($me, null);
        if ($scope !== null) {
            return $scope;
        }
        $out = [];
        try {
            foreach ($this->jadwal->rosterWithDivisi() as $x) {
                $x = (array) $x;
                if (isset($x['id']) && (string) $x['id'] !== '') {
                    $out[] = (string) $x['id'];
                }
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($out));
    }
}
