<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisKpiItem;
use App\Modules\Hris\Models\HrisKpiTemplate;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisKpiService;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Jadwal\Services\JadwalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — team KPI (K1). Phase 1 only SHOWS team KPI: crew see their
 * own division, heads theirs, HR any. Input (templates, realisation) lives
 * behind the `kpiInputAktif` setting — off until the mentor confirms the
 * items per Divisi — and takes the head of the division or HR.
 */
final class HrisKpiController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisKpiService $kpi,
        private readonly HrisLingkup $lingkup,
        private readonly JadwalService $jadwal,
    ) {
        parent::__construct($access);
    }

    public function show(Request $r): JsonResponse
    {
        $d = $r->validate([
            'divisi' => ['nullable', 'string', 'max:64'],
            'bulan' => ['required', 'date_format:Y-m'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $div = self::s($d['divisi'] ?? '');
            if ($div === '') {
                try {
                    $div = $this->jadwal->divisiUser(self::s($me['id']));
                } catch (\Throwable) {
                    $div = '';
                }
            }
            if ($div === '' || $div === 'nonshift') {
                if (! $this->lingkup->isHr($me)) {
                    throw new HrisError('validation_failed', 'Butuh divisi (tanyakan ?divisi=).', 422);
                }
                throw new HrisError('validation_failed', 'Butuh ?divisi= untuk HR.', 422);
            }
            $this->assertDivisi($me, $div);

            return $this->kpi->nilai($div, $d['bulan']);
        });
    }

    public function saveTemplate(Request $r): JsonResponse
    {
        $d = $r->validate([
            'id' => ['nullable', 'string', 'max:32'],
            'divisi' => ['required', 'string', 'max:64'],
            'berlaku_dari' => ['required', 'date_format:Y-m-d'],
            'item' => ['required', 'array', 'min:1', 'max:12'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->assertTulis($me, self::s($d['divisi']));
            $t = $this->kpi->saveTemplate($d, self::s($me['name']));

            return ['id' => $t->id, 'divisi' => $t->divisi];
        }, 201);
    }

    public function saveRealisasi(Request $r): JsonResponse
    {
        $d = $r->validate([
            'item_id' => ['required', 'string', 'max:32'],
            'bulan' => ['required', 'date_format:Y-m'],
            'nilai' => ['required', 'numeric'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $item = HrisKpiItem::query()->find(self::s($d['item_id']));
            if (! $item) {
                throw new HrisError('not_found', 'Item KPI tidak ada.', 404);
            }
            $t = HrisKpiTemplate::query()->find($item->template_id);
            $this->assertTulis($me, $t ? (string) $t->divisi : '');
            $this->kpi->saveRealisasi($d, self::s($me['name']));

            return $this->kpi->nilai((string) $t->divisi, $d['bulan']);
        });
    }

    /** Crew see their own division; heads theirs; HR any. */
    private function assertDivisi(array $me, string $div): void
    {
        if ($this->lingkup->isHr($me)) {
            return;
        }
        if ($this->lingkup->isHead($me) && in_array($div, $this->lingkup->headDivisi($me), true)) {
            return;
        }
        try {
            $sendiri = $this->jadwal->divisiUser(self::s($me['id']));
        } catch (\Throwable) {
            $sendiri = '';
        }
        if ($div !== '' && $div === $sendiri) {
            return;
        }
        throw new HrisError('forbidden', 'KPI tim hanya untuk divisi sendiri.', 403);
    }

    /** Input takes the head of the division or HR (plus the feature gate). */
    private function assertTulis(array $me, string $div): void
    {
        if ($div !== '' && $this->lingkup->isHead($me)
            && in_array($div, $this->lingkup->headDivisi($me), true)
            && self::s($me['id']) !== '') {
            $this->kpi->assertInputAktif();

            return;
        }
        $this->lingkup->assertHr($me, 'Mengatur KPI');
        $this->kpi->assertInputAktif();
    }
}
