<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Akademi\Services\AkademiRecords;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisPengajuan;
use App\Modules\Hris\Models\HrisPushToken;
use App\Modules\Hris\Models\HrisReminderLog;
use App\Modules\Jadwal\Services\HeadDirectory;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Divisi;

/**
 * Push reminders 1–7 (hris.md), via Expo → FCM/APNs. Every send is
 * idempotent per (jenis, ref, user) in hris_reminder_log — a repeated run
 * never pushes twice — and every payload carries only the recipient's own
 * data (their shift, their PH, their request), never names or biometrics.
 *
 * Time-based reminders take an optional $nowMs so tests run on fixed
 * instants; production passes null (server time).
 */
final class HrisReminderService
{
    public function __construct(
        private readonly ExpoPushService $push,
        private readonly HrisSettingsService $settings,
        private readonly HrisBalanceService $saldo,
        private readonly JadwalService $jadwal,
        private readonly HeadDirectory $heads,
        private readonly OfficeAccess $access,
        private readonly AccountRepository $users,
        private readonly AkademiRecords $akademi,
        private readonly HrisAuditService $audit,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    // ---------------------------------------------------------------- devices

    public function daftarkanPerangkat(string $userId, mixed $token, mixed $platform): void
    {
        $userId = self::s($userId);
        $token = trim((string) $token);
        if ($userId === '') {
            throw new HrisError('unauthenticated', 'Login required.', 401);
        }
        if (! ExpoPushService::tokenValid($token)) {
            throw new HrisError('validation_failed', 'Token perangkat tidak valid.', 422);
        }
        $platform = strtolower(self::s($platform));
        if ($platform !== 'ios' && $platform !== 'android') {
            $platform = 'android';
        }
        // Re-registering moves a hand-me-down phone to its new owner.
        HrisPushToken::query()->updateOrCreate(['token' => mb_substr($token, 0, 190)],
            ['user_id' => $userId, 'platform' => $platform, 'terakhir_dilihat' => now()]);
    }

    public function hapusPerangkat(string $userId, mixed $token): bool
    {
        return (bool) HrisPushToken::query()->where('user_id', self::s($userId))
            ->where('token', trim((string) $token))->delete();
    }

    // ---------------------------------------------------------------- sending

    public function pushAktif(): bool
    {
        return (bool) $this->settings->get('pushAktif', true);
    }

    public function sudahDiingatkan(string $jenis, string $ref, string $userId): bool
    {
        return HrisReminderLog::query()->where('jenis', $jenis)->where('ref', $ref)
            ->where('user_id', $userId)->exists();
    }

    /**
     * Send one reminder (idempotent). Returns true when a push went out.
     *
     * @param  array{jenis?:string,ref?:string}  $data
     */
    public function ingatkan(string $userId, string $jenis, string $ref,
        string $title, string $body, array $data = []): bool
    {
        $userId = self::s($userId);
        if ($userId === '' || ! $this->pushAktif()) {
            return false;
        }
        $jenis = mb_substr(self::s($jenis), 0, 64);
        $ref = mb_substr(self::s($ref), 0, 128);
        if ($this->sudahDiingatkan($jenis, $ref, $userId)) {
            return false;
        }
        $tokens = HrisPushToken::query()->where('user_id', $userId)->pluck('token')->all();
        if ($tokens === []) {
            return false;
        }
        $pesan = [];
        foreach ($tokens as $t) {
            $pesan[] = ['to' => $t, 'title' => $title, 'body' => $body,
                'data' => ['jenis' => $jenis, 'ref' => $ref] + $data];
        }
        $hasil = $this->push->kirim($pesan);
        if ($hasil['terkirim'] > 0) {
            HrisReminderLog::query()->create(['jenis' => $jenis, 'ref' => $ref,
                'user_id' => $userId, 'waktu' => PunchRules::ms()]);
        }

        return $hasil['terkirim'] > 0;
    }

    // ---------------------------------------------------------------- 1 late

    /**
     * 1. No MASUK X minutes after the shift starts (X = reminderTelatMenit).
     * One roster read, one punch read per user with a started shift.
     *
     * @return array{diingatkan:int}
     */
    public function telatMasuk(?int $nowMs = null): array
    {
        $now = $nowMs ?? PunchRules::ms();
        $tgl = PunchRules::tglWib($now);
        $menit = PunchRules::menitWib($now);
        $batas = (int) $this->settings->get('reminderTelatMenit', 30);
        try {
            $rows = $this->jadwal->shiftRange('', $tgl, $tgl)['rows'] ?? [];
        } catch (\Throwable) {
            return ['diingatkan' => 0];
        }
        $n = 0;
        foreach ($rows as $r) {
            $uid = (string) ($r['u'] ?? '');
            $mulai = PunchRules::jamKeMenit($r['m'] ?? '');
            if ($uid === '' || $mulai < 0 || ! empty($r['libur'])) {
                continue;
            }
            if ($menit < $mulai + $batas) {
                continue;
            }
            $masuk = HrisKetukan::query()->where('user_id', $uid)
                ->where('tanggal_kerja', $tgl)->where('arah', 'MASUK')
                ->where('status', '!=', 'DITOLAK')->exists();
            if ($masuk) {
                continue;
            }
            if ($this->ingatkan($uid, 'belum_masuk', $uid.'|'.$tgl,
                'Belum absen masuk', 'Shift Anda sudah mulai. Absen sekarang sebelum antre.')) {
                $n++;
            }
        }

        return ['diingatkan' => $n];
    }

    // ---------------------------------------------------------------- 2+3 geofence

    /**
     * 2+3. Geofence crossings from the device (no automatic punch, A8):
     * EXIT with a MASUK but no PULANG → nudge to punch out; ENTER with no
     * MASUK → nudge to punch in. One nudge per user per day per direction.
     */
    public function geofence(string $userId, mixed $lokasiId, mixed $tipe, ?int $nowMs = null): array
    {
        $userId = self::s($userId);
        $tipe = strtoupper(self::s($tipe));
        if ($tipe !== 'ENTER' && $tipe !== 'EXIT') {
            throw new HrisError('validation_failed', 'Tipe geofence harus ENTER atau EXIT.', 422);
        }
        $now = $nowMs ?? PunchRules::ms();
        $tgl = PunchRules::tglWib($now);
        $masuk = HrisKetukan::query()->where('user_id', $userId)->where('tanggal_kerja', $tgl)
            ->where('arah', 'MASUK')->where('status', '!=', 'DITOLAK')->exists();
        $pulang = HrisKetukan::query()->where('user_id', $userId)->where('tanggal_kerja', $tgl)
            ->where('arah', 'PULANG')->where('status', '!=', 'DITOLAK')->exists();
        $terkirim = false;
        if ($tipe === 'EXIT' && $masuk && ! $pulang) {
            $terkirim = $this->ingatkan($userId, 'keluar_tanpa_pulang', $userId.'|'.$tgl,
                'Sudah pulang?', 'Anda keluar area tanpa absen PULANG. Absen sekarang.');
        } elseif ($tipe === 'ENTER' && ! $masuk) {
            $terkirim = $this->ingatkan($userId, 'masuk_tanpa_absen', $userId.'|'.$tgl,
                'Sudah di area', 'Anda masuk area tanpa absen MASUK. Absen sekarang.');
        }

        return ['tipe' => $tipe, 'terkirim' => $terkirim];
    }

    // ---------------------------------------------------------------- 4 PH

    /** 4. PH expiring within 14 days (L4 feeds B4's reminder). */
    public function phSegeraHangus(?string $today = null): array
    {
        $today = PunchRules::tglValid($today ?? PunchRules::tglWib(PunchRules::ms()));
        $batas = date('Y-m-d', strtotime($today.' +14 days'));
        $jatuh = HrisSaldoMutasi::query()->where('jenis', 'PH')
            ->whereColumn('batch_id', 'id')
            ->whereNotNull('kedaluwarsa')
            ->whereBetween('kedaluwarsa', [$today, $batas])->get();
        $n = 0;
        foreach ($jatuh as $e) {
            if ($this->saldo->sisaBatch($e->id) <= 0) {
                continue;
            }
            $tgl = $e->kedaluwarsa->format('Y-m-d');
            if ($this->ingatkan($e->user_id, 'ph_hangus', $e->user_id.'|'.$e->id,
                'PH segera hangus', "PH Anda hangus {$tgl}. Ajukan sebelum hangus.")) {
                $n++;
            }
        }

        return ['diingatkan' => $n];
    }

    // ---------------------------------------------------------------- 5 waiting

    /** 5. Requests waiting: heads for MENUNGGU, HR for MENUNGGU_HRD. */
    public function pengajuanMenunggu(): array
    {
        $n = 0;
        $kepala = $this->heads->headsByDivisi();
        foreach (HrisPengajuan::query()->whereIn('status', ['MENUNGGU', 'MENUNGGU_HRD'])->get() as $r) {
            if ($r->status === 'MENUNGGU') {
                try {
                    $div = $this->jadwal->divisiUser($r->user_id);
                } catch (\Throwable) {
                    $div = Divisi::NONSHIFT;
                }
                $tujuan = ($div !== Divisi::NONSHIFT && isset($kepala[$div]) && is_array($kepala[$div]))
                    ? $kepala[$div] : $this->hrIds();
                foreach ($tujuan as $uid) {
                    $uid = trim((string) $uid);
                    if ($uid === '' || $uid === $r->user_id) {
                        continue;
                    }
                    if ($this->ingatkan($uid, 'pengajuan_menunggu', $r->id.'|kepala',
                        'Pengajuan menunggu', "Pengajuan {$r->jenis} menunggu keputusan Anda.")) {
                        $n++;
                    }
                }
            } else {
                foreach ($this->hrIds() as $uid) {
                    if ($this->ingatkan($uid, 'pengajuan_menunggu', $r->id.'|hrd',
                        'Pengajuan menunggu HRD', "Pengajuan {$r->jenis} menunggu keputusan HRD.")) {
                        $n++;
                    }
                }
            }
        }

        return ['diingatkan' => $n];
    }

    /** @return array<int,string> HR user ids (module admins + hr setting). */
    public function hrIds(): array
    {
        $out = [];
        try {
            foreach ($this->users->allUsers() as $u) {
                $id = (string) ($u['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $adm = $this->access->adminModules($id);
                if (in_array('*', $adm, true) || in_array('hris', $adm, true)) {
                    $out[] = $id;
                }
            }
        } catch (\Throwable) {
        }
        foreach ((array) $this->settings->get('hr', []) as $id) {
            $id = trim((string) $id);
            if ($id !== '' && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return array_values($out);
    }

    // ---------------------------------------------------------------- 6 decided

    /** 6a. A queue decision went out: tell the crew member. */
    public function putusanKetukan(string $userId, string $ketukanId, string $status): bool
    {
        $status = strtoupper(trim($status));

        return $this->ingatkan($userId, 'putusan_antrean', $ketukanId.'|'.$status,
            'Antrean diputus', 'Absen Anda diputus: '.$status.'. Lihat riwayat.');
    }

    /** 6b. A request decision went out: tell the requester. */
    public function putusanPengajuan(string $userId, string $pengajuanId, string $status): bool
    {
        $status = strtoupper(trim($status));

        return $this->ingatkan($userId, 'putusan_pengajuan', $pengajuanId.'|'.$status,
            'Pengajuan diputus', 'Pengajuan Anda: '.$status.'. Lihat daftar pengajuan.');
    }

    // ---------------------------------------------------------------- 7 study

    /**
     * 7. Akademi/training near deadline: weekly nudge listing unfinished
     * mandatory materials. (Program-level deadlines ride along when Akademi
     * exposes them; the mandatory list is the phase-1 signal.)
     */
    public function tenggatBelajar(?int $nowMs = null): array
    {
        $now = $nowMs ?? PunchRules::ms();
        $pekan = gmdate('oW', (int) floor(PunchRules::wibMs($now) / 1000));
        try {
            $materi = $this->akademi->list('materials', ['published' => '1', 'mandatory' => '1'], 1, 10000)['rows'] ?? [];
            $anggota = $this->jadwal->rosterWithDivisi();
        } catch (\Throwable) {
            return ['diingatkan' => 0];
        }
        if ($materi === [] || $anggota === []) {
            return ['diingatkan' => 0];
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
            return ['diingatkan' => 0];
        }
        $n = 0;
        foreach ($anggota as $a) {
            $a = (array) $a;
            $uid = (string) ($a['id'] ?? '');
            if ($uid === '') {
                continue;
            }
            try {
                $rows = $this->akademi->progressList($uid, null)['rows'] ?? [];
            } catch (\Throwable) {
                continue;
            }
            $selesai = 0;
            foreach ($rows as $r) {
                $e = is_array($r['entry'] ?? null) ? $r['entry'] : [];
                if ((string) ($r['materialId'] ?? '') !== '' && (($e['status'] ?? '') === 'done' || ! empty($e['passed']))) {
                    $selesai++;
                }
            }
            // Count only mandatory materials; progress rows of retired
            // materials still count (they were done when assigned).
            $kurang = max(0, count($ids) - $selesai);
            if ($kurang > 0 && $this->ingatkan($uid, 'tenggat_belajar', $uid.'|'.$pekan,
                'Materi belum selesai', "Masih ada {$kurang} materi wajib. Selesaikan sebelum tenggat.")) {
                $n++;
            }
            if ($n >= 500) {
                break;
            }
        }

        return ['diingatkan' => $n];
    }
}
