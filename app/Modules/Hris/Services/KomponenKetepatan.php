<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Hris\Models\HrisKetukan;

/**
 * Ketepatan: on-time arrivals over MASUK VALID punches that carry a shift
 * snapshot. Late = arrival past mulai + toleransiTelat (overnight-aware,
 * like AbsensiService::hitungHari). Late still earns the meal day — this
 * component is where punctuality shows instead. A day whose VALID PULANG
 * left early (pulang_awal) is not on time either (A23).
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
        $pulangAwal = [];
        foreach (HrisKetukan::query()->where('user_id', $userId)->where('arah', 'PULANG')
            ->where('status', 'VALID')->whereBetween('tanggal_kerja', [$dari, $sampai])->get() as $p) {
            if ($this->adalahPulangAwal($p)) {
                $pulangAwal[$p->tanggal_kerja] = true;
            }
        }
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
            if (max(0, $datang - ($mulai + $tol)) <= 0 && empty($pulangAwal[$r->tanggal_kerja])) {
                $tepat++;
            }
        }
        if ($dinilai === 0) {
            return ['skor' => 0.0, 'kelengkapan' => 0.0, 'detail' => ['dinilai' => 0]];
        }

        return ['skor' => round($tepat / $dinilai * 100, 2), 'kelengkapan' => 100.0,
            'detail' => ['tepat' => $tepat, 'dinilai' => $dinilai]];
    }

    /**
     * Did this VALID PULANG leave before its target? The stored info row
     * says so; older rows recompute live from the MASUK ≤ 18 h earlier.
     */
    private function adalahPulangAwal(HrisKetukan $p): bool
    {
        $info = $p->info;
        if (is_array($info) && array_key_exists('pulang_awal', $info)) {
            return (bool) $info['pulang_awal'];
        }
        $masuk = HrisKetukan::query()
            ->where('user_id', $p->user_id)->where('arah', 'MASUK')->where('status', '!=', 'DITOLAK')
            ->where('waktu', '>=', (int) $p->waktu - 18 * 3600 * 1000)
            ->where('waktu', '<=', (int) $p->waktu)
            ->orderByDesc('waktu')->first();
        if ($masuk === null) {
            return false;
        }
        $target = PunchRules::targetDurasiMenit(
            $masuk->shift_sumber !== '' ? $masuk->shift_sumber : null,
            $masuk->shift_mulai, $masuk->shift_selesai,
            $this->settings->get('durasiKerja', ['default' => 540, 'orang' => []]),
            (string) $p->user_id);
        $durasi = (int) floor(((int) $p->waktu - (int) $masuk->waktu) / 60000);

        return $durasi < $target;
    }
}
