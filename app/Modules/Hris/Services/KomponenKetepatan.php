<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKetukan;

/**
 * Ketepatan: on-time arrivals over MASUK VALID punches that carry a shift
 * snapshot. Late = arrival past mulai + toleransiTelat (overnight-aware,
 * like AbsensiService::hitungHari). Late still earns the meal day — this
 * component is where punctuality shows instead.
 */
final class KomponenKetepatan implements SkorKomponen
{
    public function __construct(private readonly HrisSettingsService $settings) {}

    public function kunci(): string
    {
        return 'ketepatan';
    }

    public function hitung(string $userId, string $bulan): array
    {
        [$dari, $sampai] = [$bulan.'-01', date('Y-m-t', strtotime($bulan.'-01'))];
        $tol = (int) $this->settings->get('toleransiTelat', 5);
        $rows = HrisKetukan::query()->where('user_id', $userId)->where('arah', 'MASUK')
            ->where('status', 'VALID')->whereBetween('tanggal_kerja', [$dari, $sampai])->get();
        $tepat = 0;
        $dinilai = 0;
        foreach ($rows as $r) {
            $mulai = PunchRules::jamKeMenit($r->shift_mulai);
            if ($mulai < 0) {
                continue;
            }
            $datang = PunchRules::menitWib((int) $r->waktu);
            if ($datang + 720 < $mulai) {
                $datang += 1440;
            }
            $dinilai++;
            if (max(0, $datang - ($mulai + $tol)) <= 0) {
                $tepat++;
            }
        }
        if ($dinilai === 0) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['dinilai' => 0]];
        }

        return ['skor' => round($tepat / $dinilai * 100, 2), 'kelengkapan' => 100.0,
            'detail' => ['tepat' => $tepat, 'dinilai' => $dinilai]];
    }
}
