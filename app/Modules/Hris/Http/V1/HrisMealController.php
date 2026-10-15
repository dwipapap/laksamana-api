<?php

declare(strict_types=1);

namespace App\Modules\Hris\Http\V1;

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisLingkup;
use App\Modules\Hris\Services\HrisMealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/hris — meal allowance (P1–P2). Rates and locks are HR-only;
 * previews, recaps and exports are scoped by Lingkup. A locked month is an
 * immutable snapshot; exporting an open month is a live draft.
 */
final class HrisMealController extends HrisBaseController
{
    public function __construct(
        OfficeAccess $access,
        private readonly HrisMealService $meal,
        private readonly HrisLingkup $lingkup,
    ) {
        parent::__construct($access);
    }

    // ---------------------------------------------------------------- rates

    public function tarifs(Request $r): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me) {
            $this->lingkup->assertHr($me, 'Melihat tarif');

            return $this->meal->tarifs();
        });
    }

    public function saveTarif(Request $r): JsonResponse
    {
        $d = $r->validate([
            'id' => ['nullable', 'string', 'max:32'],
            'divisi' => ['nullable', 'string', 'max:64'],
            'grade' => ['nullable', 'string', 'max:64'],
            'nominal' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'berlaku_dari' => ['required', 'date_format:Y-m-d'],
        ]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Menyimpan tarif');
            $row = $this->meal->saveTarif($d, self::s($me['name']));

            return ['id' => $row->id];
        }, 201);
    }

    public function deleteTarif(Request $r, string $id): JsonResponse
    {
        $me = $this->me($r);

        return $this->run(function () use ($me, $id) {
            $this->lingkup->assertHr($me, 'Menghapus tarif');
            if (! $this->meal->deleteTarif($id, self::s($me['name']))) {
                throw new HrisError('not_found', 'Tarif tidak ada.', 404);
            }

            return ['hapus' => 1];
        });
    }

    // ---------------------------------------------------------------- recap

    public function preview(Request $r): JsonResponse
    {
        $d = $r->validate(['bulan' => ['required', 'date_format:Y-m']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->scoped($me, $this->meal->pratinjau($d['bulan'])));
    }

    public function lock(Request $r): JsonResponse
    {
        $d = $r->validate(['bulan' => ['required', 'date_format:Y-m']]);
        $me = $this->me($r);

        return $this->run(function () use ($me, $d) {
            $this->lingkup->assertHr($me, 'Mengunci periode');

            return $this->meal->kunci($d['bulan'], self::s($me['name']));
        });
    }

    public function rekap(Request $r): JsonResponse
    {
        $d = $r->validate(['bulan' => ['required', 'date_format:Y-m']]);
        $me = $this->me($r);

        return $this->run(fn () => $this->scoped($me, $this->meal->rekap($d['bulan'])));
    }

    public function ekspor(Request $r)
    {
        $d = $r->validate(['bulan' => ['required', 'date_format:Y-m']]);
        $me = $this->me($r);
        try {
            $scope = $this->lingkup->scopeUsers($me, null);
            $csv = $this->meal->eksporCsv($d['bulan']);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
        if ($scope !== null) {
            // Heads and crew export only their rows.
            $lines = explode("\n", trim($csv['isi']));
            $head = array_shift($lines);
            $lines = array_values(array_filter($lines,
                fn ($l) => in_array(explode(',', $l)[0] ?? '', $scope, true)));
            $csv['isi'] = $head."\n".implode("\n", $lines).($lines === [] ? '' : "\n");
        }

        return response($csv['isi'], 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$csv['nama'].'"',
        ]);
    }

    /** Filter recap rows down to the caller's Lingkup. */
    private function scoped(array $me, array $rekap): array
    {
        $scope = $this->lingkup->scopeUsers($me, null);
        if ($scope === null) {
            return $rekap;
        }
        $rekap['baris'] = array_values(array_filter($rekap['baris'],
            fn ($b) => in_array($b['user_id'], $scope, true)));
        $rekap['total'] = array_sum(array_column($rekap['baris'], 'total'));

        return $rekap;
    }
}
