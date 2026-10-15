<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisPelanggaran;

/**
 * Disiplin: 100 minus the month's active penalties (RINGAN 10, SEDANG 25,
 * BERAT 50), floored at 0. No news is complete news: kelengkapan is always
 * 100 — a quiet month is data, not missing data.
 */
final class KomponenDisiplin implements SkorKomponen
{
    public function kunci(): string
    {
        return 'disiplin';
    }

    public function hitung(string $userId, string $bulan): array
    {
        [$dari, $sampai] = [$bulan.'-01', date('Y-m-t', strtotime($bulan.'-01'))];
        $rows = HrisPelanggaran::query()->where('user_id', $userId)->where('status', 'AKTIF')
            ->whereBetween('tanggal', [$dari, $sampai])->get();
        $potong = 0;
        $n = 0;
        foreach ($rows as $r) {
            $potong += HrisDisiplinService::TINGKAT[strtoupper($r->tingkat)] ?? 10;
            $n++;
        }

        return ['skor' => round(max(0.0, 100 - $potong), 2), 'kelengkapan' => 100.0,
            'detail' => ['pelanggaran' => $n, 'potongan' => $potong]];
    }
}
