<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Hris\Models\HrisKaryawan;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisRosterEvent;
use App\Modules\Hris\Models\HrisUbahShift;
use App\Modules\Jadwal\Services\JadwalService;
use Illuminate\Support\Carbon;

/**
 * Shift changes for one crew member on one work date (issue #346): the
 * crew files, the division head approves directly. No second HR step.
 * CUTI/PH/IZIN keep their own flow in HrisRequestService.
 *
 * Approval intent is committed before the Jadwal side effect. The durable
 * APPLYING state freezes the approver; Jadwal compares the submitted source
 * fingerprint under the cell row lock and records an operation marker on the
 * legacy row. Core Jadwal writes share the finalization transaction.
 */
final class HrisUbahShiftService
{
    /** @var callable(string,HrisUbahShift):void|null test seam before apply */
    public static $ujiGagalTulis = null;

    /** @var callable(HrisUbahShift):void|null test seam after Jadwal commit */
    public static $ujiSesudahTulis = null;

    /** @var callable(HrisUbahShift):void|null test seam after intent commit, before the row CAS */
    public static $ujiSebelumCAS = null;

    public function __construct(
        private readonly OfficeAccess $access,
        private readonly AccountRepository $users,
        private readonly JadwalService $jadwal,
        private readonly HrisShiftService $shift,
        private readonly HrisDivisiService $divisi,
        private readonly HrisRosterEventService $event,
        private readonly HrisAuditService $audit,
    ) {}

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
     * @param  array{tanggal_kerja?:mixed,shift_baru?:mixed,alasan?:mixed,idempotency_key?:mixed}  $in
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
        $kodeBaru = self::s($in['shift_baru'] ?? '');
        $alasan = mb_substr(self::s($in['alasan'] ?? ''), 0, 500);
        if ($alasan === '') {
            throw new HrisError('validation_failed', 'Alasan ubah shift wajib diisi.', 422);
        }
        $kunci = self::s($in['idempotency_key'] ?? '');
        if ($kunci === '' || strlen($kunci) > 64) {
            throw new HrisError('validation_failed', 'idempotency_key wajib (maks 64 karakter).', 422);
        }

        $defs = $this->defs();
        $raw = $this->jadwal->readCell($uid, $tgl);
        // Build the frozen snapshot and source fingerprint from one observed
        // cell representation; separate grid and raw reads could straddle an edit.
        $sel = $raw;
        $defLama = $sel === null ? null : ($defs[(string) ($sel['t'] ?? '')] ?? null);
        $this->assertBisaDiajukan($uid, $tgl, $sel, $defLama, $kodeBaru, $defs, $k);
        $defBaru = $defs[$kodeBaru];
        $awal = $this->potret($sel, $defLama);
        $tujuan = $this->potret(['t' => $kodeBaru, 'm' => '', 's' => '',
            'n' => (string) ($sel['n'] ?? '')], $defBaru);
        $sourceHash = $this->jadwal->cellFingerprint($raw);

        $dibuat = true;
        $row = HrisUbahShift::query()->getConnection()->transaction(function () use (
            $uid, $nama, $tgl, $kodeBaru, $alasan, $kunci, $awal, $tujuan, $sourceHash, &$dibuat) {
            $sama = HrisUbahShift::query()->where('user_id', $uid)
                ->where('idempotency_key', $kunci)->lockForUpdate()->first();
            if ($sama) {
                if ($sama->status !== HrisUbahShift::MENUNGGU
                    || (string) $sama->tanggal_kerja->format('Y-m-d') !== $tgl
                    || (string) $sama->shift_baru_kode !== $kodeBaru) {
                    throw new HrisError('idempotency_bentrok',
                        'Kunci ini sudah dipakai pengajuan lain. Pakai kunci baru.', 409);
                }
                $dibuat = false;

                return $sama;
            }
            $aktif = HrisUbahShift::query()->where('user_id', $uid)
                ->where('tanggal_kerja', $tgl)
                ->whereIn('status', [HrisUbahShift::MENUNGGU, HrisUbahShift::APPLYING])
                ->lockForUpdate()->first();
            if ($aktif) {
                throw new HrisError('sudah_menunggu',
                    'Tanggal ini sudah ada pengajuan ubah shift yang menunggu. Batalkan dulu sebelum mengajukan lagi.', 409,
                    ['id' => $aktif->id]);
            }

            return HrisUbahShift::query()->create([
                'user_id' => $uid, 'user_ulid' => $this->users->userUlid($uid),
                'tanggal_kerja' => $tgl, 'shift_lama_kode' => (string) $awal['kode'],
                'shift_lama' => $awal, 'shift_baru_kode' => $kodeBaru, 'shift_baru' => $tujuan,
                'jadwal_sumber_hash' => $sourceHash,
                'alasan' => $alasan, 'status' => HrisUbahShift::MENUNGGU,
                'diajukan_oleh' => mb_substr($nama, 0, 120), 'diajukan_at' => PunchRules::ms(),
                'idempotency_key' => $kunci,
            ]);
        });
        $this->audit->record($nama, 'ubah_shift_diajukan', 'hris_ubah_shift', $row->id,
            ['status' => HrisUbahShift::MENUNGGU, 'tanggal' => $tgl, 'dari' => (string) $awal['kode'], 'ke' => $kodeBaru]);

        return $this->detail($row->fresh(), $this->sel($uid, $tgl)) + ['dibuat' => $dibuat];
    }

    /** Submit-time gate: explicit working source cell, valid target, right time. */
    private function assertBisaDiajukan(string $uid, string $tgl, ?array $sel, ?array $defLama,
        string $kodeBaru, array $defs, HrisKaryawan $k): void
    {
        if ($tgl < self::hariIni()) {
            throw new HrisError('tanggal_lewat',
                'Tanggal kerja sudah lewat. Hubungi HR untuk perubahan tanggal lampau.', 422);
        }
        if ($sel === null || $defLama === null) {
            throw new HrisError('tanpa_jadwal',
                'Tanggal itu belum ada jadwal shift. Ubah shift hanya untuk tanggal yang sudah dijadwalkan; hubungi kepala atau HR untuk penjadwalan.', 422);
        }
        if ($defLama['libur']) {
            throw new HrisError('tanpa_jadwal',
                'Tanggal itu bukan shift kerja. Ubah shift hanya untuk tanggal shift kerja.', 422);
        }
        $defBaru = $defs[$kodeBaru] ?? null;
        if ($defBaru === null || ! $defBaru['aktif'] || $defBaru['libur']) {
            throw new HrisError('validation_failed', 'Shift tujuan harus shift kerja yang aktif.', 422);
        }
        if ($kodeBaru === (string) ($sel['t'] ?? '')) {
            throw new HrisError('tidak_ada_perubahan', 'Shift tujuan sama dengan shift saat ini.', 422);
        }
        $divKode = (string) ($k->divisi?->kode ?? $this->jadwal->divisiUser($uid));
        if ($defBaru['divisi'] !== [] && ! in_array($divKode, $defBaru['divisi'], true)) {
            throw new HrisError('validation_failed', "Shift {$defBaru['nama']} bukan untuk divisimu.", 422);
        }
        if (in_array($kodeBaru, ['OFF', 'CUTI', 'IZIN'], true)) {
            throw new HrisError('validation_failed', 'Ubah shift tidak untuk OFF, cuti, atau izin. Pakai jenis pengajuan yang sesuai.', 422);
        }
        if ($this->sudahMulai($tgl, $defLama, $sel)) {
            throw new HrisError('shift_mulai',
                'Shift asal sudah mulai. Hubungi HR untuk perubahan setelah shift berjalan.', 422);
        }
        if ($this->sudahMulai($tgl, $defBaru)) {
            throw new HrisError('shift_mulai',
                'Shift tujuan sudah mulai. Pilih shift yang belum dimulai atau hubungi HR.', 422);
        }
        if (HrisKetukan::query()->where('user_id', $uid)->where('tanggal_kerja', $tgl)->exists()) {
            throw new HrisError('sudah_ada_ketukan',
                'Tanggal itu sudah ada ketukan absen. Hubungi HR untuk perubahan setelah absen tercatat.', 422);
        }
    }

    // ------------------------------------------------------------------ read

    /**
     * @return array{data:array<int,array<string,mixed>>,meta:array{total:int,page:int,per_page:int}}
     */
    public function riwayatSaya(array $me, ?string $status, int $page, int $perPage): array
    {
        $uid = self::s($me['id'] ?? '');
        $this->karyawanAktif($uid);
        $q = HrisUbahShift::query()->where('user_id', $uid)->orderByDesc('tanggal_kerja')->orderByDesc('created_at');
        $status = strtoupper(self::s($status ?? ''));
        if ($status !== '') {
            $this->assertStatus($status);
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->skip(max(0, ($page - 1) * $perPage))->take($perPage)->get();
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->detail($r, $this->sel($uid, (string) $r->tanggal_kerja->format('Y-m-d')));
        }

        return ['data' => $out, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage]];
    }

    public function detailSaya(array $me, string $id): array
    {
        $uid = self::s($me['id'] ?? '');
        $this->karyawanAktif($uid);
        $row = HrisUbahShift::query()->find(self::s($id))
            ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        if ($row->user_id !== $uid) {
            throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        }

        return $this->detail($row, $this->sel($uid, (string) $row->tanggal_kerja->format('Y-m-d')));
    }

    public function batal(array $me, string $id, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        $this->karyawanAktif($uid);
        $row = HrisUbahShift::query()->getConnection()->transaction(function () use ($uid, $nama, $id, $in) {
            $r = HrisUbahShift::query()->where('user_id', $uid)
                ->lockForUpdate()->find(self::s($id))
                ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
            HrisDivisiService::assertVersion($r->version, $in);
            if ($r->status !== HrisUbahShift::MENUNGGU) {
                throw new HrisError('sudah_final', 'Pengajuan ini sudah final dan tidak bisa dibatalkan.', 422);
            }
            $r->fill(['status' => HrisUbahShift::DIBATALKAN,
                'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
                'diputus_at' => PunchRules::ms()])->save();

            return $r;
        });
        $this->audit->record($nama, 'ubah_shift_dibatalkan', 'hris_ubah_shift', $row->id,
            ['status' => HrisUbahShift::DIBATALKAN]);

        return $this->detail($row->fresh(), $this->sel($uid, (string) $row->tanggal_kerja->format('Y-m-d')));
    }

    /** Head queue: MENUNGGU rows this caller may decide (head scope, never self). */
    public function antreanKepala(array $me): array
    {
        $uid = self::s($me['id'] ?? '');
        if ($uid === '') {
            return [];
        }
        $milik = $this->divisi->petaKepala()[$uid] ?? [];
        $rows = HrisUbahShift::query()->whereIn('status', [HrisUbahShift::MENUNGGU, HrisUbahShift::APPLYING])
            ->orderBy('tanggal_kerja')->orderBy('created_at')->get();
        $nama = $this->users->namesByIds($rows->pluck('user_id')->unique()->all());
        $out = [];
        foreach ($rows as $r) {
            if ($r->status === HrisUbahShift::APPLYING) {
                if ($r->diputus_oleh_id !== $uid) {
                    continue;
                }
                $k = HrisKaryawan::query()->with('divisi')->where('user_id', $r->user_id)->first();
                $out[] = $this->ringkas($r, (string) ($nama[$r->user_id] ?? $r->user_id),
                    $k?->divisi ? ['kode' => $k->divisi->kode, 'nama' => $k->divisi->nama] : null);

                continue;
            }
            if ($milik === []) {
                continue;
            }
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

    /** HR fallback queue for Kelola: MENUNGGU rows no head can decide. */
    public function antreanHr(array $me): array
    {
        $this->assertApprover($me);
        $rows = HrisUbahShift::query()->whereIn('status', [HrisUbahShift::MENUNGGU, HrisUbahShift::APPLYING])
            ->orderBy('tanggal_kerja')->orderBy('created_at')->get();
        $nama = $this->users->namesByIds($rows->pluck('user_id')->unique()->all());
        $out = [];
        foreach ($rows as $r) {
            if ($r->status === HrisUbahShift::APPLYING) {
                if ($r->diputus_oleh_id !== self::s($me['id'] ?? '')) {
                    continue;
                }
            } elseif (! $this->butuhHr($r)) {
                continue;
            }
            $k = HrisKaryawan::query()->with('divisi')->where('user_id', $r->user_id)->first();
            $out[] = $this->ringkas($r, (string) ($nama[$r->user_id] ?? $r->user_id),
                $k?->divisi ? ['kode' => $k->divisi->kode, 'nama' => $k->divisi->nama] : null);
        }

        return $out;
    }

    public function detailKelola(array $me, string $id): array
    {
        $this->assertApprover($me);
        $row = HrisUbahShift::query()->find(self::s($id))
            ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);

        return $this->detail($row, $this->sel($row->user_id, (string) $row->tanggal_kerja->format('Y-m-d')));
    }

    /** Decision detail is visible to the current head or the HR fallback. */
    public function detailPemutus(array $me, string $id): array
    {
        $uid = self::s($me['id'] ?? '');
        $row = HrisUbahShift::query()->find(self::s($id))
            ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
        if ($row->status === HrisUbahShift::APPLYING && $row->diputus_oleh_id === $uid) {
            // A persisted approval may be resumed by its frozen approver even
            // if the division mapping changed after the side effect began.
        } else {
            $this->assertBolehPutus($uid, $row);
        }

        return $this->detail($row, $this->sel($row->user_id, (string) $row->tanggal_kerja->format('Y-m-d')));
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
        if ($status !== HrisUbahShift::DISETUJUI && $status !== HrisUbahShift::DITOLAK) {
            throw new HrisError('validation_failed', 'Keputusan hanya boleh DISETUJUI atau DITOLAK.', 422);
        }
        $catatan = mb_substr(self::s($in['catatan'] ?? ''), 0, 500);
        if ($status === HrisUbahShift::DITOLAK && $catatan === '') {
            throw new HrisError('validation_failed', 'Penolakan wajib disertai catatan untuk kru.', 422);
        }

        $resume = false;
        $row = HrisUbahShift::query()->getConnection()->transaction(function () use (
            $uid, $nama, $id, $in, $status, $catatan, &$resume) {
            $r = HrisUbahShift::query()->lockForUpdate()->find(self::s($id))
                ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
            HrisDivisiService::assertVersion($r->version, $in);
            if ($r->status === HrisUbahShift::APPLYING && $r->diputus_oleh_id === $uid
                && $status === HrisUbahShift::DISETUJUI) {
                $resume = true;

                return $r;
            }
            $this->assertBolehPutus($uid, $r);
            if (in_array($r->status, [HrisUbahShift::DISETUJUI, HrisUbahShift::DITOLAK,
                HrisUbahShift::DIBATALKAN], true)) {
                if ($r->status === $status) {
                    return $r;
                }
                throw new HrisError('already_decided', 'Pengajuan ini sudah diputus.', 409);
            }
            if ($r->status === HrisUbahShift::APPLYING) {
                throw new HrisError('apply_in_progress',
                    'Persetujuan sedang dipulihkan oleh pemutus yang sama. Tunggu atau muat ulang pengajuan.', 409);
            }
            if ($status === HrisUbahShift::DISETUJUI) {
                $r->fill(['status' => HrisUbahShift::APPLYING, 'diputus_oleh_id' => $uid,
                    'diputus_oleh' => mb_substr($nama, 0, 120), 'diputus_at' => PunchRules::ms(),
                    'catatan' => $catatan === '' ? null : $catatan])->save();

                return $r;
            }
            $r->fill(['status' => HrisUbahShift::DITOLAK,
                'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
                'diputus_at' => PunchRules::ms(), 'catatan' => $catatan])->save();

            return $r;
        });

        if ($row->status === HrisUbahShift::DITOLAK) {
            $this->audit->record($nama, 'ubah_shift_ditolak', 'hris_ubah_shift', $row->id,
                ['status' => HrisUbahShift::DITOLAK, 'tanggal' => (string) $row->tanggal_kerja->format('Y-m-d')]);

            return $this->detail($row->fresh(), $this->sel($row->user_id, (string) $row->tanggal_kerja->format('Y-m-d')));
        }

        if ($row->status === HrisUbahShift::DISETUJUI) {
            return $this->detail($row, $this->sel($row->user_id, (string) $row->tanggal_kerja->format('Y-m-d')));
        }

        return $this->terapkan($row->fresh(), $resume);
    }

    /**
     * Head in current scope (never self) or HR fallback (division without
     * a head, or the requester is a head themself).
     */
    private function assertBolehPutus(string $uid, HrisUbahShift $row): void
    {
        if ($uid === '') {
            throw new HrisError('forbidden', 'Putusan ubah shift hanya oleh kepala divisi atau HR.', 403);
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
        throw new HrisError('forbidden', 'Putusan ubah shift hanya oleh kepala divisi kru tersebut atau HR pengganti.', 403);
    }

    /** True when no head can decide this row, so HR steps in via Kelola. */
    private function butuhHr(HrisUbahShift $row): bool
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
            throw new HrisError('forbidden', 'Riwayat roster hanya untuk Admin Modul HRIS.', 403);
        }

        return $uid;
    }

    // ----------------------------------------------------------------- apply

    /** Resume the frozen APPLYING operation while holding the request lock. */
    private function terapkan(HrisUbahShift $intent, bool $resume): array
    {
        $connection = HrisUbahShift::query()->getConnection();
        $tgl = (string) $intent->tanggal_kerja->format('Y-m-d');
        $sideEffect = false;
        $writeAttempted = false;
        try {
            $result = $connection->transaction(function () use ($intent, $tgl, $resume,
                &$sideEffect, &$writeAttempted): array {
                $r = HrisUbahShift::query()->lockForUpdate()->findOrFail($intent->id);
                if ($r->status === HrisUbahShift::DISETUJUI) {
                    return ['row' => $r];
                }
                if ($r->status !== HrisUbahShift::APPLYING || $r->diputus_oleh_id !== $intent->diputus_oleh_id) {
                    throw new HrisError('apply_in_progress', 'Persetujuan ini tidak lagi dapat dipulihkan oleh pemutus ini.', 409);
                }
                try {
                    $nama = (string) $r->diputus_oleh;
                    $sel = $this->sel($r->user_id, $tgl);
                    $target = ['t' => (string) $r->shift_baru_kode,
                        'm' => self::s($r->shift_baru['m'] ?? ''), 's' => self::s($r->shift_baru['s'] ?? ''),
                        'n' => self::s($r->shift_baru['n'] ?? '')];
                    if (self::$ujiSebelumCAS) {
                        (self::$ujiSebelumCAS)($r);
                    }
                    $applied = $this->jadwal->applyCellIfUnchanged(
                        $r->user_id, $tgl, (string) $r->jadwal_sumber_hash, $target, $r->id, $nama,
                        function () use ($r, $sel): void {
                            [, $k] = $this->karyawanAktif($r->user_id, utamakanPesan: true);
                            $this->assertSiapTerap($r, $sel, $this->defs(), $k);
                            if (self::$ujiGagalTulis) {
                                (self::$ujiGagalTulis)('jadwal', $r);
                            }
                        },
                        function () use (&$writeAttempted): void {
                            $writeAttempted = true;
                        }
                    );
                    if ($applied['state'] === 'conflict') {
                        throw new HrisError('sel_berubah',
                            'Sel jadwal berubah setelah pengajuan dibuat. Muat ulang dan minta kru mengajukan kembali.', 409,
                            ['terkini' => $applied['cell']]);
                    }
                    $sideEffect = true;
                    if (self::$ujiSesudahTulis) {
                        (self::$ujiSesudahTulis)($r);
                    }
                    $r->fill(['status' => HrisUbahShift::DISETUJUI,
                        'diputus_at' => $r->diputus_at ?? PunchRules::ms()])->save();
                    if (! $this->event->adaEvent($r->id)) {
                        $k = HrisKaryawan::query()->with('divisi')->where('user_id', $r->user_id)->first();
                        $this->event->catat(HrisRosterEvent::UBAH_SHIFT, $nama, [
                            'user_id' => $r->user_id, 'user_ulid' => $this->users->userUlid($r->user_id),
                            'divisi_kode' => $k?->divisi?->kode, 'divisi_nama' => $k?->divisi?->nama,
                            'tanggal_kerja' => $tgl, 'sel_lama' => $r->shift_lama, 'sel_baru' => $r->shift_baru,
                            'ref_pengajuan_id' => $r->id, 'diajukan_oleh' => $r->diajukan_oleh,
                            'diajukan_at' => (int) $r->diajukan_at,
                            'diputus_oleh' => $nama, 'diputus_at' => (int) $r->diputus_at,
                            'catatan' => $r->alasan,
                        ]);
                    }
                    $this->audit->record($nama, 'ubah_shift_disetujui', 'hris_ubah_shift', $r->id,
                        ['status' => HrisUbahShift::DISETUJUI, 'tanggal' => $tgl,
                            'dari' => (string) $r->shift_lama_kode, 'ke' => (string) $r->shift_baru_kode]);

                    return ['row' => $r];
                } catch (HrisError $e) {
                    if (! $resume && ! $sideEffect && ! $writeAttempted) {
                        $r->fill(['status' => HrisUbahShift::MENUNGGU, 'diputus_oleh_id' => null,
                            'diputus_oleh' => null, 'diputus_at' => null, 'catatan' => null])->save();

                        return ['error' => $e];
                    }
                    throw $e;
                }
            });

            if (isset($result['error'])) {
                throw $result['error'];
            }
            $row = $result['row'];

            return $this->detail($row, $this->sel($row->user_id, $tgl));
        } catch (HrisError $e) {
            $nama = (string) $intent->diputus_oleh;
            $this->audit->record($nama, 'ubah_shift_gagal_terap', 'hris_ubah_shift', $intent->id,
                ['status' => HrisUbahShift::query()->find($intent->id)?->status, 'galat' => $e->errorCode]);
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $nama = (string) $intent->diputus_oleh;
            $this->audit->record($nama, 'ubah_shift_gagal_terap', 'hris_ubah_shift', $intent->id,
                ['status' => HrisUbahShift::query()->find($intent->id)?->status, 'galat' => 'terap_gagal']);
            throw new HrisError('terap_gagal',
                'Persetujuan sedang dipulihkan. Coba buka detail dan ulangi persetujuan.', 500);
        }
    }

    /** Revalidation at decision time: authority already checked, now facts. */
    private function assertSiapTerap(HrisUbahShift $row, ?array $sel, array $defs, HrisKaryawan $k): void
    {
        $tgl = (string) $row->tanggal_kerja->format('Y-m-d');
        $awal = $row->shift_lama ?? [];
        if ($sel === null || (string) ($sel['t'] ?? '') !== (string) ($awal['kode'] ?? '')
            || self::s($sel['m'] ?? '') !== self::s($awal['m'] ?? '')
            || self::s($sel['s'] ?? '') !== self::s($awal['s'] ?? '')) {
            throw new HrisError('sel_berubah',
                'Sel jadwal sudah berubah dari sumber lain. Tinjau nilai terkini sebelum memutus.', 409,
                ['terkini' => $sel === null ? null : $this->potret($sel, $defs[(string) ($sel['t'] ?? '')] ?? null)]);
        }
        $defBaru = $defs[(string) $row->shift_baru_kode] ?? null;
        if ($defBaru === null || ! $defBaru['aktif'] || $defBaru['libur']) {
            throw new HrisError('shift_tak_tersedia', 'Shift tujuan sudah tidak tersedia. Tolak dan minta kru mengajukan ulang.', 422);
        }
        $defLama = $defs[(string) $row->shift_lama_kode] ?? null;
        if ($defLama === null || $defLama['libur']) {
            throw new HrisError('shift_tak_tersedia', 'Shift asal tidak lagi tersedia. Tinjau ulang sebelum memutus.', 422);
        }
        $tujuan = $row->shift_baru ?? [];
        if ((string) ($tujuan['nama'] ?? '') !== (string) $defBaru['nama']
            || (string) ($tujuan['mulai'] ?? '') !== (string) $defBaru['mulai']
            || (string) ($tujuan['selesai'] ?? '') !== (string) $defBaru['selesai']
            || ($tujuan['blok'] ?? []) !== ($defBaru['blok'] ?? [])) {
            throw new HrisError('shift_tak_tersedia', 'Definisi shift tujuan berubah setelah pengajuan. Minta kru mengajukan ulang.', 409);
        }
        $divKode = (string) ($k->divisi?->kode ?? $this->jadwal->divisiUser($row->user_id));
        if ($defBaru['divisi'] !== [] && ! in_array($divKode, $defBaru['divisi'], true)) {
            throw new HrisError('shift_tak_tersedia', "Shift tujuan bukan untuk divisi {$divKode} lagi.", 422);
        }
        if ($this->sudahMulai($tgl, $defLama, $sel)) {
            throw new HrisError('shift_mulai', 'Shift asal sudah mulai. Hubungi HR untuk perubahan setelah shift berjalan.', 422);
        }
        if ($this->sudahMulai($tgl, $defBaru, $tujuan)) {
            throw new HrisError('shift_mulai', 'Shift tujuan sudah mulai. Hubungi HR untuk perubahan setelah shift berjalan.', 422);
        }
        if (HrisKetukan::query()->where('user_id', $row->user_id)->where('tanggal_kerja', $tgl)->exists()) {
            throw new HrisError('sudah_ada_ketukan', 'Tanggal itu sudah ada ketukan absen. Hubungi HR.', 422);
        }
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

    /** Explicit roster cell of one crew member on one date, if any. */
    private function sel(string $uid, string $tgl): ?array
    {
        return $this->jadwal->readCell($uid, $tgl);
    }

    /** Frozen snapshot of a cell: code, def detail, effective hours, note. */
    private function potret(?array $sel, ?array $def): ?array
    {
        if ($sel === null || $def === null) {
            return null;
        }

        return ['kode' => (string) ($sel['t'] ?? ''), 'nama' => (string) ($def['nama'] ?? ''),
            'mulai' => self::s($sel['m'] ?? '') !== '' ? self::s($sel['m']) : (string) ($def['mulai'] ?? ''),
            'selesai' => self::s($sel['s'] ?? '') !== '' ? self::s($sel['s']) : (string) ($def['selesai'] ?? ''),
            'blok' => $def['blok'] ?? [], 'libur' => (bool) ($def['libur'] ?? false),
            'm' => self::s($sel['m'] ?? ''), 's' => self::s($sel['s'] ?? ''), 'n' => self::s($sel['n'] ?? '')];
    }

    /** Whether the effective shift already started (WIB, first block, work date). */
    private function sudahMulai(string $tgl, array $def, ?array $cell = null): bool
    {
        $blok = $def['blok'] ?? [];
        $mulai = self::s($cell['m'] ?? '');
        if ($mulai === '') {
            $mulai = is_array($blok[0] ?? null)
                ? (string) ($blok[0]['mulai'] ?? '') : (string) ($def['mulai'] ?? '');
        }
        if ($mulai === '') {
            return true;
        }
        $now = Carbon::now('Asia/Jakarta');

        return $tgl < $now->format('Y-m-d')
            || ($tgl === $now->format('Y-m-d') && $now->format('H:i') >= $mulai);
    }

    private function assertStatus(string $status): void
    {
        if (! in_array($status, [HrisUbahShift::MENUNGGU, HrisUbahShift::APPLYING, HrisUbahShift::DISETUJUI,
            HrisUbahShift::DITOLAK, HrisUbahShift::DIBATALKAN], true)) {
            throw new HrisError('validation_failed', 'Status tidak dikenal.', 422);
        }
    }

    private function ringkas(HrisUbahShift $r, string $nama, ?array $divisi): array
    {
        return [
            'id' => $r->id, 'user_id' => $r->user_id, 'nama' => $nama, 'divisi' => $divisi,
            'tanggal_kerja' => $r->tanggal_kerja->format('Y-m-d'),
            'shift_lama_kode' => $r->shift_lama_kode, 'shift_baru_kode' => $r->shift_baru_kode,
            'status' => $r->status, 'diajukan_oleh' => $r->diajukan_oleh, 'diajukan_at' => (int) $r->diajukan_at,
            'diputus_oleh' => $r->diputus_oleh,
            'diputus_at' => $r->diputus_at === null ? null : (int) $r->diputus_at,
            'berubah' => (int) $r->version,
        ];
    }

    /** Full detail: frozen before/after plus the live cell with conflict flag. */
    private function detail(HrisUbahShift $r, ?array $kini): array
    {
        $awal = $r->shift_lama ?? [];
        $baru = $r->shift_baru ?? [];
        $live = $kini === null ? null : $this->potret($kini, $this->defs()[(string) ($kini['t'] ?? '')] ?? null);

        return [
            'id' => $r->id, 'user_id' => $r->user_id,
            'nama' => (string) ($this->users->namesByIds([$r->user_id])[$r->user_id] ?? $r->user_id),
            'tanggal_kerja' => $r->tanggal_kerja->format('Y-m-d'),
            'shift_lama' => $awal, 'shift_baru' => $baru, 'terkini' => $live,
            'konflik' => $live !== null && ($live['kode'] !== ($awal['kode'] ?? null)
                || $live['m'] !== ($awal['m'] ?? null) || $live['s'] !== ($awal['s'] ?? null))
                && ($live['kode'] !== ($baru['kode'] ?? null)
                    || $live['m'] !== ($baru['m'] ?? null) || $live['s'] !== ($baru['s'] ?? null)),
            'status' => $r->status, 'alasan' => $r->alasan,
            'diajukan_oleh' => $r->diajukan_oleh, 'diajukan_at' => (int) $r->diajukan_at,
            'diputus_oleh' => $r->diputus_oleh,
            'diputus_at' => $r->diputus_at === null ? null : (int) $r->diputus_at,
            'catatan' => $r->catatan, 'berubah' => (int) $r->version,
        ];
    }
}
