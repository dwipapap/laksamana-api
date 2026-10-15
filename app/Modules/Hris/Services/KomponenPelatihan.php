<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Akademi\Services\AkademiRecords;

/**
 * Pelatihan: published Akademi materials completed (status done or a passed
 * quiz), all-time — training accumulates, so the month only snapshots it.
 * Unreadable Akademi scores 0 with 0 completeness, never an exception.
 */
final class KomponenPelatihan implements SkorKomponen
{
    public function __construct(private readonly AkademiRecords $akademi) {}

    public function kunci(): string
    {
        return 'pelatihan';
    }

    public function hitung(string $userId, string $bulan): array
    {
        try {
            $total = (int) ($this->akademi->list('materials', ['published' => '1'], 1, 1)['total'] ?? 0);
            $rows = $this->akademi->progressList($userId, null)['rows'] ?? [];
        } catch (\Throwable) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['akademi_tidak_terbaca' => true]];
        }
        $selesai = [];
        foreach ($rows as $r) {
            $e = is_array($r['entry'] ?? null) ? $r['entry'] : [];
            if (($e['status'] ?? '') === 'done' || ! empty($e['passed'])) {
                $selesai[(string) ($r['materialId'] ?? '')] = true;
            }
        }
        $n = count($selesai);
        if ($total <= 0) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['selesai' => $n, 'materi' => 0]];
        }

        return ['skor' => round(min(100.0, $n / $total * 100), 2), 'kelengkapan' => 100.0,
            'detail' => ['selesai' => $n, 'materi' => $total]];
    }
}
