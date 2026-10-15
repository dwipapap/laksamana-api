<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Jadwal\Services\JadwalService;

/**
 * Kehadiran: MASUK VALID days over scheduled days. Scheduled = roster cells
 * with a real shift code (OFF/CUTI/IZIN cells are absences, not obligations).
 * Holiday work counts as hadir — they did attend.
 */
final class KomponenKehadiran implements SkorKomponen
{
    public function __construct(private readonly JadwalService $jadwal) {}

    public function kunci(): string
    {
        return 'kehadiran';
    }

    public function hitung(string $userId, string $bulan): array
    {
        [$dari, $sampai] = [$bulan.'-01', $bulan.'-28'];
        $sampai = date('Y-m-t', strtotime($dari));
        try {
            $cells = $this->jadwal->shiftRange($userId, $dari, $sampai)['rows'] ?? [];
        } catch (\Throwable) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['jadwal_tidak_terbaca' => true]];
        }
        $jadwal = [];
        foreach ($cells as $c) {
            $t = strtoupper((string) ($c['t'] ?? ''));
            if ($t === '' || in_array($t, ['OFF', 'CUTI', 'IZIN'], true)) {
                continue;
            }
            $jadwal[(string) ($c['d'] ?? '')] = true;
        }
        $hadir = HrisKetukan::query()->where('user_id', $userId)->where('arah', 'MASUK')
            ->where('status', 'VALID')->whereBetween('tanggal_kerja', [$dari, $sampai])
            ->distinct()->pluck('tanggal_kerja')->all();
        $nJadwal = count($jadwal);
        if ($nJadwal === 0) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['terjadwal' => 0, 'hadir' => count($hadir)]];
        }
        $skor = min(100.0, count($hadir) / $nJadwal * 100);

        return ['skor' => round($skor, 2), 'kelengkapan' => 100.0,
            'detail' => ['terjadwal' => $nJadwal, 'hadir' => count($hadir)]];
    }
}
