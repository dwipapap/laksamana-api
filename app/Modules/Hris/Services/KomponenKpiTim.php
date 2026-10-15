<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Jadwal\Services\JadwalService;

/**
 * KPI tim: the member's division achievement (K1, 0–120 capped to 100).
 * No individual KPI exists — the team carries the member here.
 */
final class KomponenKpiTim implements SkorKomponen
{
    public function __construct(
        private readonly HrisKpiService $kpi,
        private readonly JadwalService $jadwal,
    ) {}

    public function kunci(): string
    {
        return 'kpi_tim';
    }

    public function hitung(string $userId, string $bulan): array
    {
        try {
            $div = $this->jadwal->divisiUser($userId);
            $n = $this->kpi->nilai($div, $bulan);
        } catch (\Throwable) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['gagal' => true]];
        }
        if ($n['skor'] === null) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['divisi' => $div, 'template' => false]];
        }

        return ['skor' => round(min(100.0, (float) $n['skor']), 2),
            'kelengkapan' => round((float) $n['kelengkapan'], 2),
            'detail' => ['divisi' => $div, 'capaian' => $n['skor']]];
    }
}
