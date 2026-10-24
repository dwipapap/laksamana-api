<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisKoreksiAbsen;
use App\Modules\Jadwal\Services\JadwalService;
use Illuminate\Support\Carbon;

/**
 * Attendance corrections for a past work date: the crew files a missing
 * MASUK or PULANG with the clock time and reason, the division head
 * decides directly (HR fallback when no head can), and approval writes
 * the punch row. Nothing lands in hris_ketukan while MENUNGGU.
 *
 * One transaction writes the punch and finalizes the request, so no
 * durable APPLYING state is needed (unlike the roster CAS in
 * HrisUbahShiftService, which spans the core and Jadwal connections).
 */
final class HrisKoreksiAbsenService
{
    public function __construct(
        private readonly OfficeAccess $access,
        private readonly AccountRepository $users,
        private readonly JadwalService $jadwal,
        private readonly HrisShiftService $shift,
        private readonly HrisDivisiService $divisi,
        private readonly HrisAuditService $audit,
        private readonly HrisSettingsService $settings,
        private readonly HrisPeriodeKunci $periode,
    ) {}

    /** Corrections only look back this many days (setting koreksiMaksHari). */
    private function maksMundurHari(): int
    {
        return max(1, (int) $this->settings->get('koreksiMaksHari', 7));
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    private static function hariIni(): string
    {
        return PunchRules::tglWib(PunchRules::ms());
    }

    // ---------------------------------------------------------------- submit

    /**
     * @param  array{tanggal_kerja?:mixed,arah?:mixed,jam?:mixed,alasan?:mixed,idempotency_key?:mixed}  $in
     */
    public function ajukan(array $me, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        [$u, $k] = $this->karyawanAktif($uid);
        $tgl = PunchRules::tglValid(self::s($in['tanggal_kerja'] ?? ''));
        if ($tgl === '') {
            throw new HrisError('validation_failed', 'Tanggal kerja butuh format YYYY-MM-DD.', 422);
        }
        $arah = strtoupper(self::s($in['arah'] ?? ''));
        if ($arah !== 'MASUK' && $arah !== 'PULANG') {
            throw new HrisError('validation_failed', 'Arah harus MASUK (clock-in) atau PULANG (clock-out).', 422);
        }
        $jam = self::s($in['jam'] ?? '');
        if (PunchRules::jamKeMenit($jam) < 0) {
            throw new HrisError('validation_failed', 'Jam butuh format HH:MM, contoh 08:05.', 422);
        }
        $alasan = mb_substr(self::s($in['alasan'] ?? ''), 0, 500);
        if ($alasan === '') {
            throw new HrisError('validation_failed', 'Alasan koreksi absen wajib diisi.', 422);
        }
        $kunci = self::s($in['idempotency_key'] ?? '');
        if ($kunci === '' || strlen($kunci) > 64) {
            throw new HrisError('validation_failed', 'idempotency_key wajib (maks 64 karakter).', 422);
        }
        $this->assertTanggalBisaDiajukan($tgl);
        // A decided punch for that date+direction already settles it: only
        // HR can touch recorded punches, through the queue decision.
        $ada = HrisKetukan::query()->where('user_id', $uid)->where('tanggal_kerja', $tgl)
            ->where('arah', $arah)->where('status', '!=', 'DITOLAK')->first();
        if ($ada) {
            throw new HrisError('sudah_ada_ketukan',
                'Tanggal itu sudah ada ketukan '.$arah.'. Hubungi HR untuk perubahan setelah absen tercatat.', 422);
        }

        $dibuat = true;
        $row = HrisKoreksiAbsen::query()->getConnection()->transaction(function () use (
            $uid, $nama, $tgl, $arah, $jam, $alasan, $kunci, &$dibuat) {
            $sama = HrisKoreksiAbsen::query()->where('user_id', $uid)
                ->where('idempotency_key', $kunci)->lockForUpdate()->first();
            if ($sama) {
                if ($sama->status !== HrisKoreksiAbsen::MENUNGGU
                    || (string) $sama->tanggal_kerja->format('Y-m-d') !== $tgl
                    || (string) $sama->arah !== $arah) {
                    throw new HrisError('idempotency_bentrok',
                        'Kunci ini sudah dipakai pengajuan lain. Pakai kunci baru.', 409);
                }
                $dibuat = false;

                return $sama;
            }
            $aktif = HrisKoreksiAbsen::query()->where('user_id', $uid)
                ->where('tanggal_kerja', $tgl)->where('arah', $arah)
                ->where('status', HrisKoreksiAbsen::MENUNGGU)
                ->lockForUpdate()->first();
            if ($aktif) {
                throw new HrisError('sudah_menunggu',
                    'Tanggal dan arah ini sudah ada pengajuan koreksi yang menunggu. Batalkan dulu sebelum mengajukan lagi.', 409,
                    ['id' => $aktif->id]);
            }

            return HrisKoreksiAbsen::query()->create([
                'user_id' => $uid, 'user_ulid' => $this->users->userUlid($uid),
                'tanggal_kerja' => $tgl, 'arah' => $arah, 'jam' => $jam,
                'alasan' => $alasan, 'status' => HrisKoreksiAbsen::MENUNGGU,
                'diajukan_oleh' => mb_substr($nama, 0, 120), 'diajukan_at' => PunchRules::ms(),
                'idempotency_key' => $kunci,
            ]);
        });
        $this->audit->record($nama, 'koreksi_absen_diajukan', 'hris_koreksi_absen', $row->id,
            ['status' => HrisKoreksiAbsen::MENUNGGU, 'tanggal' => $tgl, 'arah' => $arah, 'jam' => $jam]);

        return $this->detail($row) + ['dibuat' => $dibuat];
    }

    /** Submit-time gate: past dates only, inside the correction window. */
    private function assertTanggalBisaDiajukan(string $tgl): void
    {
        if ($tgl > self::hariIni()) {
            throw new HrisError('tanggal_masa_depan',
                'Koreksi hanya untuk tanggal yang sudah lewat, bukan tanggal depan.', 422);
        }
        $maks = $this->maksMundurHari();
        $batas = date('Y-m-d', strtotime(self::hariIni().' -'.$maks.' days'));
        if ($tgl < $batas) {
            throw new HrisError('terlalu_lama',
                'Koreksi maksimal '.$maks.' hari ke belakang. Hubungi HR untuk tanggal yang lebih lama.', 422,
                ['maks_hari' => $maks]);
        }
        $this->periode->pastikanTerbuka($tgl);
    }

    // ------------------------------------------------------------------ read

    /**
     * @return array{data:array<int,array<string,mixed>>,meta:array{total:int,page:int,per_page:int}}
     */
    public function riwayatSaya(array $me, ?string $status, int $page, int $perPage): array
    {
        $uid = self::s($me['id'] ?? '');
        $this->karyawanAktif($uid);
        $q = HrisKoreksiAbsen::query()->where('user_id', $uid)->orderByDesc('tanggal_kerja')->orderByDesc('created_at');
        $status = strtoupper(self::s($status ?? ''));
        if ($status !== '') {
            $this->assertStatus($status);
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->skip(max(0, ($page - 1) * $perPage))->take($perPage)->get();
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->detail($r);
        }

        return ['data' => $out, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]];
    }

    public function detailSaya(array $me, string $id): array
    {
        $uid = self::s($me['id'] ?? '');
        $this->karyawanAktif($uid);
        $row = HrisKoreksiAbsen::query()->find(self::s($id))
            ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        if ($row->user_id !== $uid) {
            throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        }

        return $this->detail($row);
    }

    public function batal(array $me, string $id, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        $this->karyawanAktif($uid);
        $row = HrisKoreksiAbsen::query()->getConnection()->transaction(function () use ($uid, $nama, $id, $in) {
            $r = HrisKoreksiAbsen::query()->where('user_id', $uid)
                ->lockForUpdate()->find(self::s($id))
                ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
            HrisDivisiService::assertVersion($r->version, $in);
            if ($r->status !== HrisKoreksiAbsen::MENUNGGU) {
                throw new HrisError('sudah_final', 'Pengajuan ini sudah final dan tidak bisa dibatalkan.', 422);
            }
            $r->fill(['status' => HrisKoreksiAbsen::DIBATALKAN,
                'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
                'diputus_at' => PunchRules::ms()])->save();

            return $r;
        });
        $this->audit->record($nama, 'koreksi_absen_dibatalkan', 'hris_koreksi_absen', $row->id,
            ['status' => HrisKoreksiAbsen::DIBATALKAN]);

        return $this->detail($row->fresh());
    }

    /** Head queue: MENUNGGU rows this caller may decide (head scope, never self). */
    public function antreanKepala(array $me): array
    {
        $uid = self::s($me['id'] ?? '');
        if ($uid === '') {
            return [];
        }
        $milik = $this->divisi->petaKepala()[$uid] ?? [];
        if ($milik === []) {
            return [];
        }
        $rows = HrisKoreksiAbsen::query()->where('status', HrisKoreksiAbsen::MENUNGGU)
            ->orderBy('tanggal_kerja')->orderBy('created_at')->get();
        $nama = $this->users->namesByIds($rows->pluck('user_id')->unique()->all());
        $out = [];
        foreach ($rows as $r) {
            if ($r->user_id === $uid) {
                continue;
            }
            $k = HrisKaryawan::query()->with('divisi')->where('user_id', $r->user_id)->first();
            if (! $k || ! in_array((string) $k->divisi_id, $milik, true)) {
                continue;
            }
            $out[] = $this->ringkas($r, (string) ($nama[$r->user_id] ?? $r->user_id),
                $k->divisi ? ['kode' => $k->divisi->kode, 'nama' => $k->divisi->nama] : null);
        }

        return $out;
    }

    /**
     * @return array{data:array<int,array<string,mixed>>,meta:array{total:int,page:int,per_page:int},menunggu_hr:array<int,array<string,mixed>>}
     */
    public function daftarKelola(array $me, ?string $status, int $page, int $perPage): array
    {
        $this->assertApprover($me);
        $q = HrisKoreksiAbsen::query()->orderByDesc('tanggal_kerja')->orderByDesc('created_at');
        $status = strtoupper(self::s($status ?? ''));
        if ($status !== '') {
            $this->assertStatus($status);
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->skip(max(0, ($page - 1) * $perPage))->take($perPage)->get();
        $semua = HrisKoreksiAbsen::query()->where('status', HrisKoreksiAbsen::MENUNGGU)->get();
        $nama = $this->users->namesByIds(
            $rows->pluck('user_id')->merge($semua->pluck('user_id'))->unique()->all());
        $out = [];
        foreach ($rows as $r) {
            $k = HrisKaryawan::query()->with('divisi')->where('user_id', $r->user_id)->first();
            $out[] = $this->ringkas($r, (string) ($nama[$r->user_id] ?? $r->user_id),
                $k?->divisi ? ['kode' => $k->divisi->kode, 'nama' => $k->divisi->nama] : null);
        }
        $tunggu = [];
        foreach ($semua as $r) {
            if (! $this->butuhHr($r)) {
                continue;
            }
            $k = HrisKaryawan::query()->with('divisi')->where('user_id', $r->user_id)->first();
            $tunggu[] = $this->ringkas($r, (string) ($nama[$r->user_id] ?? $r->user_id),
                $k?->divisi ? ['kode' => $k->divisi->kode, 'nama' => $k->divisi->nama] : null);
        }

        return ['data' => $out, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
            'menunggu_hr' => $tunggu];
    }

    public function detailKelola(array $me, string $id): array
    {
        $this->assertApprover($me);
        $row = HrisKoreksiAbsen::query()->find(self::s($id))
            ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);

        return $this->detail($row);
    }

    /** Decision detail is visible to the current head or the HR fallback. */
    public function detailPemutus(array $me, string $id): array
    {
        $uid = self::s($me['id'] ?? '');
        $row = HrisKoreksiAbsen::query()->find(self::s($id))
            ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        $this->assertBolehPutus($uid, $row);

        return $this->detail($row);
    }

    // ---------------------------------------------------------------- decide

    /**
     * @param  array{status?:mixed,catatan?:mixed,berubah?:mixed,version?:mixed}  $in
     */
    public function putuskan(array $me, string $id, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        $status = strtoupper(self::s($in['status'] ?? ''));
        if ($status !== HrisKoreksiAbsen::DISETUJUI && $status !== HrisKoreksiAbsen::DITOLAK) {
            throw new HrisError('validation_failed', 'Keputusan hanya boleh DISETUJUI atau DITOLAK.', 422);
        }
        $catatan = mb_substr(self::s($in['catatan'] ?? ''), 0, 500);
        if ($status === HrisKoreksiAbsen::DITOLAK && $catatan === '') {
            throw new HrisError('validation_failed', 'Penolakan wajib disertai catatan untuk kru.', 422);
        }

        $row = HrisKoreksiAbsen::query()->getConnection()->transaction(function () use (
            $uid, $nama, $id, $in, $status, $catatan) {
            $r = HrisKoreksiAbsen::query()->lockForUpdate()->find(self::s($id))
                ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
            HrisDivisiService::assertVersion($r->version, $in);
            $this->assertBolehPutus($uid, $r);
            if (in_array($r->status, [HrisKoreksiAbsen::DISETUJUI, HrisKoreksiAbsen::DITOLAK,
                HrisKoreksiAbsen::DIBATALKAN], true)) {
                if ($r->status === $status) {
                    return $r;
                }
                throw new HrisError('already_decided', 'Pengajuan ini sudah diputus.', 409);
            }
            if ($status === HrisKoreksiAbsen::DITOLAK) {
                $r->fill(['status' => HrisKoreksiAbsen::DITOLAK,
                    'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
                    'diputus_at' => PunchRules::ms(), 'catatan' => $catatan])->save();

                return $r;
            }
            $this->periode->pastikanTerbuka((string) $r->tanggal_kerja->format('Y-m-d'));
            $this->terapkan($r, $uid, $nama, $catatan);

            return $r;
        });

        if ($row->status === HrisKoreksiAbsen::DITOLAK) {
            $this->audit->record($nama, 'koreksi_absen_ditolak', 'hris_koreksi_absen', $row->id,
                ['status' => HrisKoreksiAbsen::DITOLAK,
                    'tanggal' => (string) $row->tanggal_kerja->format('Y-m-d'), 'arah' => (string) $row->arah]);
        } else {
            $this->audit->record($nama, 'koreksi_absen_disetujui', 'hris_koreksi_absen', $row->id,
                ['status' => HrisKoreksiAbsen::DISETUJUI,
                    'tanggal' => (string) $row->tanggal_kerja->format('Y-m-d'), 'arah' => (string) $row->arah]);
        }

        return $this->detail($row->fresh());
    }

    /** Write the approved punch inside the same transaction as the verdict. */
    private function terapkan(HrisKoreksiAbsen $r, string $uid, string $nama, string $catatan): void
    {
        $tgl = (string) $r->tanggal_kerja->format('Y-m-d');
        $arah = (string) $r->arah;
        // A decided punch for that date+direction settles it first: the
        // requester cannot approve over recorded attendance.
        $ada = HrisKetukan::query()->where('user_id', $r->user_id)->where('tanggal_kerja', $tgl)
            ->where('arah', $arah)->where('status', '!=', 'DITOLAK')->lockForUpdate()->first();
        if ($ada) {
            throw new HrisError('sudah_ada_ketukan',
                'Tanggal itu sudah ada ketukan '.$arah.'. Tolak pengajuan ini bila ketukan tercatat sudah benar.', 409);
        }
        [$u, $k] = $this->karyawanAktif($r->user_id, utamakanPesan: true);
        try {
            $waktu = (int) (Carbon::createFromFormat('Y-m-d H:i', $tgl.' '.(string) $r->jam, 'Asia/Jakarta')->getTimestampMs());
        } catch (\Throwable) {
            throw new HrisError('validation_failed', 'Jam koreksi tidak valid.', 422);
        }
        $sel = $this->jadwal->readCell($r->user_id, $tgl);
        $def = $sel === null ? null : ($this->defs()[(string) ($sel['t'] ?? '')] ?? null);
        $mulai = self::s($sel['m'] ?? '');
        if ($mulai === '') {
            $mulai = $def === null ? '' : (string) ($def['mulai'] ?? '');
        }
        $selesai = self::s($sel['s'] ?? '');
        if ($selesai === '') {
            $selesai = $def === null ? '' : (string) ($def['selesai'] ?? '');
        }
        $blokTerakhir = [];
        if (is_array($def['blok'] ?? null) && $def['blok'] !== []) {
            $blokTerakhir = $def['blok'][count($def['blok']) - 1];
        }
        HrisKetukan::query()->create([
            'user_id' => $r->user_id, 'user_ulid' => $this->users->userUlid($r->user_id),
            'tanggal_kerja' => $tgl, 'arah' => $arah, 'blok' => 1, 'waktu' => $waktu,
            'lat' => 0, 'lng' => 0, 'akurasi_m' => 0,
            'shift_kode' => $sel === null ? '' : mb_substr((string) ($sel['t'] ?? ''), 0, 32),
            'shift_mulai' => $mulai, 'shift_selesai' => $selesai,
            'lintas_hari' => $blokTerakhir !== []
                ? (string) ($blokTerakhir['selesai'] ?? '') <= (string) ($blokTerakhir['mulai'] ?? '')
                : (bool) ($def['lintas_hari'] ?? false),
            'shift_sumber' => $sel === null ? 'NONE' : 'JADWAL',
            'dalam_shift' => false,
            // VALID like a queued punch HR approved: every recap (meal
            // allowance, Kehadiran, PH) counts VALID punches only.
            'status' => 'VALID',
            'alasan_kru' => mb_substr((string) $r->alasan, 0, 255),
            'diputus_oleh' => mb_substr($nama, 0, 120), 'diputus_at' => PunchRules::ms(),
            'nota' => mb_substr($catatan, 0, 255),
        ]);
        $r->fill(['status' => HrisKoreksiAbsen::DISETUJUI,
            'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
            'diputus_at' => PunchRules::ms(), 'catatan' => $catatan === '' ? null : $catatan])->save();
    }

    /**
     * Head in current scope (never self) or HR fallback (division without
     * a head, or the requester is a head themself).
     */
    private function assertBolehPutus(string $uid, HrisKoreksiAbsen $row): void
    {
        if ($uid === '') {
            throw new HrisError('forbidden', 'Putusan koreksi absen hanya oleh kepala divisi atau HR.', 403);
        }
        if (self::s($row->user_id) === $uid) {
            throw new HrisError('forbidden', 'Pengajuan sendiri tidak bisa diputus sendiri.', 403);
        }
        $k = HrisKaryawan::query()->where('user_id', $row->user_id)->first();
        $peta = $this->divisi->petaKepala();
        if ($k && in_array((string) $k->divisi_id, $peta[$uid] ?? [], true)) {
            return;
        }
        if ($this->access->isModuleAdmin($uid, 'hris') && $this->butuhHr($row)) {
            return;
        }
        throw new HrisError('forbidden', 'Putusan koreksi absen hanya oleh kepala divisi kru tersebut atau HR pengganti.', 403);
    }

    /** True when no head can decide this row, so HR steps in via Kelola. */
    private function butuhHr(HrisKoreksiAbsen $row): bool
    {
        $peta = $this->divisi->petaKepala();
        if (isset($peta[$row->user_id])) {
            return true;
        }
        $k = HrisKaryawan::query()->with('divisi')->where('user_id', $row->user_id)->first();
        if (! $k || ! $k->divisi) {
            return true;
        }
        foreach ($peta as $divs) {
            if (in_array((string) $k->divisi_id, $divs, true)) {
                return false;
            }
        }

        return true;
    }

    private function assertApprover(array $me): string
    {
        $uid = self::s($me['id'] ?? '');
        if ($uid === '' || ! $this->access->isModuleAdmin($uid, 'hris')) {
            throw new HrisError('forbidden', 'Koreksi absen Kelola hanya untuk Admin Modul HRIS.', 403);
        }

        return $uid;
    }

    // ---------------------------------------------------------------- helpers

    private function karyawanAktif(string $uid, bool $utamakanPesan = false): array
    {
        $u = $this->users->userById($uid);
        if (! $u || (int) ($u['active'] ?? 0) !== 1) {
            throw new HrisError('karyawan_nonaktif',
                $utamakanPesan ? 'User sudah nonaktif; penerapan dibatalkan.' : 'Fitur ini hanya untuk karyawan aktif.', 422);
        }
        $k = HrisKaryawan::query()->with(['divisi', 'jabatan'])->where('user_id', $uid)->first();
        if (! $k || ($k->tanggal_keluar !== null && $k->tanggal_keluar->format('Y-m-d') < self::hariIni())) {
            throw new HrisError('karyawan_nonaktif',
                $utamakanPesan ? 'Karyawan sudah keluar; penerapan dibatalkan.' : 'Fitur ini hanya untuk karyawan aktif.', 422);
        }

        return [$u, $k];
    }

    /** @return array<string,array<string,mixed>> shift code => def shape */
    private function defs(): array
    {
        $out = [];
        foreach ($this->shift->list() as $x) {
            $out[$x['kode']] = $x;
        }

        return $out;
    }

    private function assertStatus(string $status): void
    {
        if (! in_array($status, [HrisKoreksiAbsen::MENUNGGU, HrisKoreksiAbsen::DISETUJUI,
            HrisKoreksiAbsen::DITOLAK, HrisKoreksiAbsen::DIBATALKAN], true)) {
            throw new HrisError('validation_failed', 'Status tidak dikenal.', 422);
        }
    }

    private function ringkas(HrisKoreksiAbsen $r, string $nama, ?array $divisi): array
    {
        return [
            'id' => $r->id, 'user_id' => $r->user_id, 'nama' => $nama, 'divisi' => $divisi,
            'tanggal_kerja' => $r->tanggal_kerja->format('Y-m-d'), 'arah' => (string) $r->arah,
            'jam' => (string) $r->jam,
            'status' => $r->status, 'diajukan_oleh' => $r->diajukan_oleh, 'diajukan_at' => (int) $r->diajukan_at,
            'diputus_oleh' => $r->diputus_oleh,
            'diputus_at' => $r->diputus_at === null ? null : (int) $r->diputus_at,
            'berubah' => (int) $r->version,
        ];
    }

    private function detail(HrisKoreksiAbsen $r): array
    {
        $k = HrisKaryawan::query()->with('divisi')->where('user_id', $r->user_id)->first();

        return [
            'id' => $r->id, 'user_id' => $r->user_id,
            'nama' => (string) ($this->users->namesByIds([$r->user_id])[$r->user_id] ?? $r->user_id),
            'divisi' => $k?->divisi ? ['kode' => $k->divisi->kode, 'nama' => $k->divisi->nama] : null,
            'tanggal_kerja' => $r->tanggal_kerja->format('Y-m-d'), 'arah' => (string) $r->arah,
            'jam' => (string) $r->jam,
            'status' => $r->status, 'alasan' => $r->alasan,
            'diajukan_oleh' => $r->diajukan_oleh, 'diajukan_at' => (int) $r->diajukan_at,
            'diputus_oleh' => $r->diputus_oleh,
            'diputus_at' => $r->diputus_at === null ? null : (int) $r->diputus_at,
            'catatan' => $r->catatan, 'berubah' => (int) $r->version,
        ];
    }
}
