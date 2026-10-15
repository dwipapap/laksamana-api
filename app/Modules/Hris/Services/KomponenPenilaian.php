<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisPenilaianAtasan;

/**
 * Penilaian atasan: the monthly 1–5 ratings averaged and scaled to 0–100.
 * No rating yet means 0 completeness — the head simply has not rated.
 */
final class KomponenPenilaian implements SkorKomponen
{
    public function kunci(): string
    {
        return 'penilaian';
    }

    public function hitung(string $userId, string $bulan): array
    {
        $row = HrisPenilaianAtasan::query()->where('user_id', $userId)->where('bulan', $bulan)->first();
        if (! $row || ! is_array($row->aspek) || $row->aspek === []) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['dinilai' => false]];
        }
        $vals = array_values(array_filter($row->aspek, 'is_numeric'));
        if ($vals === []) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['dinilai' => false]];
        }
        $rata = array_sum($vals) / count($vals);

        return ['skor' => round(min(100.0, max(0.0, $rata * 20)), 2), 'kelengkapan' => 100.0,
            'detail' => ['rata' => round($rata, 2), 'aspek' => count($vals)]];
    }
}
