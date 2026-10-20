<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Modules\Akademi\Services\AkademiRecords;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisOnboarding;
use App\Modules\Hris\Models\HrisOnboardingLangkah;
use App\Modules\Hris\Models\HrisPengajuan;
use App\Modules\Hris\Models\HrisSaldoMutasi;
use App\Modules\Jadwal\Services\JadwalService;

/**
 * The PWA "Perlu perhatian" box: computed on GET /hris/me/today, no push,
 * no scheduler, no stored state. Each item is
 * {kode, judul, pesan, layar, tingkat: 'info'|'penting'}.
 *
 * Time-based checks take an optional $nowMs so tests run on fixed instants;
 * production passes null (server time).
 */
final class HrisPerhatianService
{
    public function __construct(
        private readonly HrisAttendanceService $hadir,
        private readonly HrisSettingsService $settings,
        private readonly HrisBalanceService $saldo,
        private readonly HrisLingkup $lingkup,
        private readonly JadwalService $jadwal,
        private readonly AkademiRecords $akademi,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    /**
     * @return array<int,array{kode:string,judul:string,pesan:string,layar:string,tingkat:string}>
     */
    public function untuk(array $me, ?int $nowMs = null): array
    {
        $uid = self::s($me['id'] ?? '');
        if ($uid === '') {
            return [];
        }
        $now = $nowMs ?? PunchRules::ms();
        $tgl = PunchRules::tglWib($now);
        $menit = PunchRules::menitWib($now);
        $out = [];
        foreach ([$this->belumMasuk($uid, $tgl, $menit),
            $this->belumPulang($uid, $tgl, $menit),
            $this->phSegeraHangus($uid, $tgl),
            $this->pengajuanMenunggu($me),
            $this->putusanTerbaru($uid, $now),
            $this->tenggatBelajar($uid, $tgl),
        ] as $item) {
            if ($item !== null) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** 1. No MASUK X minutes after the shift starts (skips days off). */
    private function belumMasuk(string $uid, string $tgl, int $menit): ?array
    {
        try {
            $shift = $this->hadir->shiftFor($uid, $tgl);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($shift) || ! empty($shift['libur'])) {
            return null;
        }
        $batas = (int) $this->settings->get('perhatianTelatMenit', 15);
        $blok = is_array($shift['blok'] ?? null) ? $shift['blok'] : [];
        // J4: on a block shift the check belongs to the block the clock is
        // in, not to the whole-day span. The gap boundary is the same one
        // used when a MASUK is assigned to a block.
        if (count($blok) >= 2) {
            $nomor = PunchRules::blokUntuk($blok, $menit);
            $jam = $blok[$nomor - 1] ?? null;
            $mulai = PunchRules::jamKeMenit($jam['mulai'] ?? '');
            if ($mulai < 0 || $menit < $mulai + $batas) {
                return null;
            }
            $masuk = HrisKetukan::query()->where('user_id', $uid)
                ->where('tanggal_kerja', $tgl)->where('arah', 'MASUK')
                ->where('blok', $nomor)
                ->where('status', '!=', 'DITOLAK')->exists();
            if ($masuk) {
                return null;
            }

            return ['kode' => 'belum_masuk', 'judul' => 'Belum absen masuk blok '.$nomor,
                'pesan' => 'Blok '.$nomor.' shift '.self::s($shift['kode'] ?? '').' mulai '
                    .self::s($jam['mulai'] ?? '').'. Absen masuk sekarang.',
                'layar' => '/absen', 'tingkat' => 'penting'];
        }
        $mulai = PunchRules::jamKeMenit($shift['mulai'] ?? '');
        if ($mulai < 0) {
            return null;
        }
        if ($menit < $mulai + $batas) {
            return null;
        }
        $masuk = HrisKetukan::query()->where('user_id', $uid)
            ->where('tanggal_kerja', $tgl)->where('arah', 'MASUK')
            ->where('status', '!=', 'DITOLAK')->exists();
        if ($masuk) {
            return null;
        }

        return ['kode' => 'belum_masuk', 'judul' => 'Belum absen masuk',
            'pesan' => 'Shift '.self::s($shift['kode'] ?? '').' mulai '.self::s($shift['mulai'] ?? '').'. Absen masuk sekarang.',
            'layar' => '/absen', 'tingkat' => 'penting'];
    }

    /** 2. MASUK without PULANG X minutes after the shift (or block) ends. */
    private function belumPulang(string $uid, string $tgl, int $menit): ?array
    {
        // No MASUK at all means there is nothing to close, plain or block.
        $adaMasuk = HrisKetukan::query()->where('user_id', $uid)
            ->where('tanggal_kerja', $tgl)->where('arah', 'MASUK')
            ->where('status', '!=', 'DITOLAK')->exists();
        if (! $adaMasuk) {
            return null;
        }
        try {
            $shift = $this->hadir->shiftFor($uid, $tgl);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($shift) || ! empty($shift['libur'])) {
            return null;
        }
        $batas = (int) $this->settings->get('perhatianPulangMenit', 60);
        $blok = is_array($shift['blok'] ?? null) ? $shift['blok'] : [];
        // J4: every block keeps its own pair, so a missed PULANG of block 1
        // still warns after block 2 has started.
        if (count($blok) >= 2) {
            $rows = HrisKetukan::query()->where('user_id', $uid)
                ->where('tanggal_kerja', $tgl)->where('status', '!=', 'DITOLAK')
                ->get(['blok', 'arah']);
            $peta = [];
            foreach ($rows as $r) {
                $peta[max(1, (int) $r->blok)][(string) $r->arah] = true;
            }
            foreach ($blok as $i => $b) {
                $nomor = $i + 1;
                if (empty($peta[$nomor]['MASUK']) || ! empty($peta[$nomor]['PULANG'])) {
                    continue;
                }
                $mulai = PunchRules::jamKeMenit($b['mulai'] ?? '');
                $selesai = PunchRules::jamKeMenit($b['selesai'] ?? '');
                // A block that crosses midnight belongs to the next day's log,
                // like the existing lintas_hari guard below.
                if ($mulai < 0 || $selesai < 0 || $selesai <= $mulai || $menit < $selesai + $batas) {
                    continue;
                }

                return ['kode' => 'belum_pulang', 'judul' => 'Belum absen pulang blok '.$nomor,
                    'pesan' => 'Blok '.$nomor.' shift '.self::s($shift['kode'] ?? '').' selesai '
                        .self::s($b['selesai'] ?? '').'. Absen pulang sekarang.',
                    'layar' => '/absen', 'tingkat' => 'penting'];
            }

            return null;
        }
        $pulang = HrisKetukan::query()->where('user_id', $uid)
            ->where('tanggal_kerja', $tgl)->where('arah', 'PULANG')
            ->where('status', '!=', 'DITOLAK')->exists();
        if ($pulang) {
            return null;
        }
        if (! empty($shift['lintas_hari'])) {
            return null;
        }
        $selesai = PunchRules::jamKeMenit($shift['selesai'] ?? '');
        if ($selesai < 0) {
            return null;
        }
        if ($menit < $selesai + $batas) {
            return null;
        }

        return ['kode' => 'belum_pulang', 'judul' => 'Belum absen pulang',
            'pesan' => 'Shift selesai '.self::s($shift['selesai'] ?? '').'. Absen pulang sekarang.',
            'layar' => '/absen', 'tingkat' => 'penting'];
    }

    /** 3. Own PH batches expiring within 14 days with a remaining balance. */
    private function phSegeraHangus(string $uid, string $today): ?array
    {
        $batas = date('Y-m-d', strtotime($today.' +14 days'));
        $n = 0;
        $dekat = null;
        foreach (HrisSaldoMutasi::query()->where('user_id', $uid)->where('jenis', 'PH')
            ->whereColumn('batch_id', 'id')->whereNotNull('kedaluwarsa')
            ->whereBetween('kedaluwarsa', [$today, $batas])->orderBy('kedaluwarsa')->get() as $e) {
            if ($this->saldo->sisaBatch($e->id) <= 0) {
                continue;
            }
            $n++;
            $dekat ??= $e->kedaluwarsa->format('Y-m-d');
        }
        if ($n === 0) {
            return null;
        }

        return ['kode' => 'ph_hangus', 'judul' => 'PH segera hangus',
            'pesan' => $n === 1
                ? "1 PH hangus {$dekat}. Ajukan sebelum hangus."
                : "{$n} PH hangus sebelum {$dekat}. Ajukan sebelum hangus.",
            'layar' => '/pengajuan', 'tingkat' => 'info'];
    }

    /** 4. Requests waiting for your approval (approvers only: heads and HR). */
    private function pengajuanMenunggu(array $me): ?array
    {
        $isHr = $this->lingkup->isHr($me);
        if (! $isHr && ! $this->lingkup->isHead($me)) {
            return null;
        }
        if ($isHr) {
            $n = HrisPengajuan::query()
                ->whereIn('status', ['MENUNGGU', 'MENUNGGU_HRD'])->count();
        } else {
            $divisi = $this->lingkup->headDivisi($me);
            if ($divisi === []) {
                return null;
            }
            $n = 0;
            foreach (HrisPengajuan::query()->where('status', 'MENUNGGU')->get() as $r) {
                try {
                    $d = $this->jadwal->divisiUser($r->user_id);
                } catch (\Throwable) {
                    continue;
                }
                if (in_array($d, $divisi, true)) {
                    $n++;
                }
            }
        }
        if ($n === 0) {
            return null;
        }

        return ['kode' => 'pengajuan_menunggu', 'judul' => 'Pengajuan menunggu keputusan',
            'pesan' => $n === 1
                ? '1 pengajuan menunggu keputusan Anda.'
                : "{$n} pengajuan menunggu keputusan Anda.",
            'layar' => '/kelola/pengajuan', 'tingkat' => 'penting'];
    }

    /** 5. Your own request or queue decisions from the last 3 days. */
    private function putusanTerbaru(string $uid, int $now): ?array
    {
        $ambang = $now - 3 * 24 * 3600 * 1000;
        $ketukan = HrisKetukan::query()->where('user_id', $uid)
            ->whereIn('status', ['VALID', 'DITOLAK'])
            ->where('diputus_at', '>=', $ambang)->count();
        $pengajuan = HrisPengajuan::query()->where('user_id', $uid)
            ->whereIn('status', ['DISETUJUI', 'DITOLAK'])
            ->where(function ($q) use ($ambang): void {
                $q->where('head_at', '>=', $ambang)->orWhere('hrd_at', '>=', $ambang);
            })->count();
        $n = $ketukan + $pengajuan;
        if ($n === 0) {
            return null;
        }

        return ['kode' => 'putusan_terbaru', 'judul' => 'Ada keputusan baru',
            'pesan' => $n === 1
                ? '1 pengajuan atau absen Anda sudah diputus. Lihat hasilnya.'
                : "{$n} pengajuan atau absen Anda sudah diputus. Lihat hasilnya.",
            'layar' => '/riwayat', 'tingkat' => 'info'];
    }

    /** 6. A near Akademi or onboarding deadline (overdue counts as near). */
    private function tenggatBelajar(string $uid, string $today): ?array
    {
        $n = $this->akademiKurang($uid) + $this->onboardingDekat($uid, $today);
        if ($n === 0) {
            return null;
        }

        return ['kode' => 'tenggat_belajar', 'judul' => 'Tenggat belajar dekat',
            'pesan' => $n === 1
                ? '1 materi atau langkah onboarding perlu diselesaikan.'
                : "{$n} materi atau langkah onboarding perlu diselesaikan.",
            'layar' => '/onboarding', 'tingkat' => 'info'];
    }

    private function akademiKurang(string $uid): int
    {
        try {
            $materi = $this->akademi->list('materials',
                ['published' => '1', 'mandatory' => '1'], 1, 10000)['rows'] ?? [];
        } catch (\Throwable) {
            return 0;
        }
        $ids = [];
        foreach ($materi as $m) {
            $m = (array) $m;
            $id = (string) ($m['id'] ?? $m['materialId'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return 0;
        }
        try {
            $rows = $this->akademi->progressList($uid, null)['rows'] ?? [];
        } catch (\Throwable) {
            return 0;
        }
        $selesai = 0;
        foreach ($rows as $r) {
            $e = is_array($r['entry'] ?? null) ? $r['entry'] : [];
            if ((string) ($r['materialId'] ?? '') !== ''
                && (($e['status'] ?? '') === 'done' || ! empty($e['passed']))) {
                $selesai++;
            }
        }

        return max(0, count($ids) - $selesai);
    }

    private function onboardingDekat(string $uid, string $today): int
    {
        $dekat = date('Y-m-d', strtotime($today.' +7 days'));
        $ids = HrisOnboarding::query()->where('user_id', $uid)
            ->where('status', 'AKTIF')->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        return HrisOnboardingLangkah::query()->whereIn('onboarding_id', $ids)
            ->where('selesai', false)->whereNotNull('tenggat')
            ->where('tenggat', '<=', $dekat)->count();
    }
}
