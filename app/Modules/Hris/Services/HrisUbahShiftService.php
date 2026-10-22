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
 * One business command: revalidate authority and conditions, compare the
 * source cell, apply through JadwalService, then persist the result and
 * the audit in one core transaction. The frontend never writes roster
 * cells after a decision. The Jadwal write is idempotent (same target),
 * so a crash before the core finalize is recovered by re-deciding:
 * the cell already equals the target and the row finalizes without a
 * rewrite. A cell that matches neither snapshot nor target is a 409
 * conflict, never a silent overwrite.
 */
final class HrisUbahShiftService
{
    /** @var callable(string,HrisUbahShift):void|null test seam for apply failure */
    public static $ujiGagalTulis = null;

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
        $sel = $this->sel($uid, $tgl);
        $defLama = $sel === null ? null : ($defs[(string) ($sel['t'] ?? '')] ?? null);
        $this->assertBisaDiajukan($uid, $tgl, $sel, $defLama, $kodeBaru, $defs, $k);
        $defBaru = $defs[$kodeBaru];
        $awal = $this->potret($sel, $defLama);
        $tujuan = $this->potret(['t' => $kodeBaru,
            'm' => count($defBaru['blok']) >= 2 ? '' : (string) ($sel['m'] ?? ''),
            's' => count($defBaru['blok']) >= 2 ? '' : (string) ($sel['s'] ?? ''),
            'n' => (string) ($sel['n'] ?? '')], $defBaru);

        $dibuat = true;
        $row = HrisUbahShift::query()->getConnection()->transaction(function () use (
            $uid, $nama, $tgl, $kodeBaru, $alasan, $kunci, $awal, $tujuan, &$dibuat) {
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
                ->where('tanggal_kerja', $tgl)->where('status', HrisUbahShift::MENUNGGU)
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
        if ($this->sudahMulai($tgl, $defLama)) {
            throw new HrisError('shift_mulai',
                'Shift asal sudah mulai. Hubungi HR untuk perubahan setelah shift berjalan.', 422);
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
        if ($uid === '' || $this->access->isModuleAdmin($uid, 'hris')) {
            return [];
        }
        $milik = $this->divisi->petaKepala()[$uid] ?? [];
        if ($milik === []) {
            return [];
        }
        $rows = HrisUbahShift::query()->where('status', HrisUbahShift::MENUNGGU)
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

    /** HR fallback queue for Kelola: MENUNGGU rows no head can decide. */
    public function antreanHr(array $me): array
    {
        $this->assertApprover($me);
        $rows = HrisUbahShift::query()->where('status', HrisUbahShift::MENUNGGU)
            ->orderBy('tanggal_kerja')->orderBy('created_at')->get();
        $nama = $this->users->namesByIds($rows->pluck('user_id')->unique()->all());
        $out = [];
        foreach ($rows as $r) {
            if (! $this->butuhHr($r)) {
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

        $row = HrisUbahShift::query()->getConnection()->transaction(function () use ($uid, $id, $in, $status) {
            $r = HrisUbahShift::query()->lockForUpdate()->find(self::s($id))
                ?? throw new HrisError('not_found', 'Pengajuan tidak ada.', 404);
            HrisDivisiService::assertVersion($r->version, $in);
            if (in_array($r->status, [HrisUbahShift::DISETUJUI, HrisUbahShift::DITOLAK,
                HrisUbahShift::DIBATALKAN], true)) {
                if ($r->status === $status) {
                    return $r;
                }
                throw new HrisError('already_decided', 'Pengajuan ini sudah diputus.', 409);
            }
            $this->assertBolehPutus($uid, $r);

            return $r;
        });

        if ($status === HrisUbahShift::DITOLAK) {
            $row->fill(['status' => HrisUbahShift::DITOLAK,
                'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
                'diputus_at' => PunchRules::ms(), 'catatan' => $catatan])->save();
            $this->audit->record($nama, 'ubah_shift_ditolak', 'hris_ubah_shift', $row->id,
                ['status' => HrisUbahShift::DITOLAK, 'tanggal' => (string) $row->tanggal_kerja->format('Y-m-d')]);

            return $this->detail($row->fresh(), $this->sel($row->user_id, (string) $row->tanggal_kerja->format('Y-m-d')));
        }

        return $this->terapkan($nama, $uid, $row->fresh(), $catatan === '' ? null : $catatan);
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
        if ($row->user_id === $uid) {
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

    /**
     * Approve path: revalidate, write the Jadwal cell, then finalize the
     * row, the roster event and the audit in one core transaction. The
     * write targets exactly the requested cell, so re-running it is safe;
     * a cell that matches neither snapshot nor target is a conflict.
     */
    private function terapkan(string $nama, string $uid, HrisUbahShift $row, ?string $catatan): array
    {
        $tgl = (string) $row->tanggal_kerja->format('Y-m-d');
        try {
            [$u, $k] = $this->karyawanAktif($row->user_id, utamakanPesan: true);
            $sel = $this->sel($row->user_id, $tgl);
            $defs = $this->defs();
            $sudah = $this->selSamaTarget($sel, $row);
            if (! $sudah) {
                $this->assertSiapTerap($row, $sel, $defs, $k);
                $this->tulisSel($nama, $uid, $row, $sel);
            }
        } catch (HrisError $e) {
            $this->audit->record($nama, 'ubah_shift_gagal_terap', 'hris_ubah_shift', $row->id,
                ['status' => HrisUbahShift::MENUNGGU, 'galat' => $e->errorCode]);
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->audit->record($nama, 'ubah_shift_gagal_terap', 'hris_ubah_shift', $row->id,
                ['status' => HrisUbahShift::MENUNGGU, 'galat' => 'terap_gagal']);
            throw new HrisError('terap_gagal',
                'Perubahan disetujui tetapi jadwal gagal ditulis. Coba putuskan lagi.', 500);
        }
        HrisUbahShift::query()->getConnection()->transaction(function () use ($row, $nama, $uid, $catatan) {
            $r = HrisUbahShift::query()->lockForUpdate()->findOrFail($row->id);
            if ($r->status !== HrisUbahShift::MENUNGGU) {
                return;
            }
            $r->fill(['status' => HrisUbahShift::DISETUJUI,
                'diputus_oleh_id' => $uid, 'diputus_oleh' => mb_substr($nama, 0, 120),
                'diputus_at' => PunchRules::ms(), 'catatan' => $catatan])->save();
            if (! $this->event->adaEvent($r->id)) {
                $k = HrisKaryawan::query()->with('divisi')->where('user_id', $r->user_id)->first();
                $this->event->catat(HrisRosterEvent::UBAH_SHIFT, $nama, [
                    'user_id' => $r->user_id, 'user_ulid' => $this->users->userUlid($r->user_id),
                    'divisi_kode' => $k?->divisi?->kode, 'divisi_nama' => $k?->divisi?->nama,
                    'tanggal_kerja' => (string) $r->tanggal_kerja->format('Y-m-d'),
                    'sel_lama' => $r->shift_lama, 'sel_baru' => $r->shift_baru,
                    'ref_pengajuan_id' => $r->id,
                    'diajukan_oleh' => $r->diajukan_oleh, 'diajukan_at' => (int) $r->diajukan_at,
                    'diputus_oleh' => mb_substr($nama, 0, 120), 'diputus_at' => PunchRules::ms(),
                    'catatan' => $r->alasan,
                ]);
            }
            $this->audit->record($nama, 'ubah_shift_disetujui', 'hris_ubah_shift', $r->id,
                ['status' => HrisUbahShift::DISETUJUI, 'tanggal' => (string) $r->tanggal_kerja->format('Y-m-d'),
                    'dari' => (string) $r->shift_lama_kode, 'ke' => (string) $r->shift_baru_kode]);
        });

        return $this->detail(HrisUbahShift::query()->findOrFail($row->id), $this->sel($row->user_id, $tgl));
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
        $divKode = (string) ($k->divisi?->kode ?? $this->jadwal->divisiUser($row->user_id));
        if ($defBaru['divisi'] !== [] && ! in_array($divKode, $defBaru['divisi'], true)) {
            throw new HrisError('shift_tak_tersedia', "Shift tujuan bukan untuk divisi {$divKode} lagi.", 422);
        }
        if ($this->sudahMulai($tgl, $defBaru)) {
            throw new HrisError('shift_mulai', 'Shift tujuan sudah mulai. Hubungi HR untuk perubahan setelah shift berjalan.', 422);
        }
        if (HrisKetukan::query()->where('user_id', $row->user_id)->where('tanggal_kerja', $tgl)->exists()) {
            throw new HrisError('sudah_ada_ketukan', 'Tanggal itu sudah ada ketukan absen. Hubungi HR.', 422);
        }
    }

    /** The single Jadwal write, mirroring the roster authority. */
    private function tulisSel(string $nama, string $uid, HrisUbahShift $row, ?array $sel): void
    {
        if (self::$ujiGagalTulis) {
            (self::$ujiGagalTulis)('jadwal', $row);

            return;
        }
        $tgl = (string) $row->tanggal_kerja->format('Y-m-d');
        $baru = $row->shift_baru ?? [];
        $this->jadwal->saveCells(
            [['u' => $row->user_id, 'd' => $tgl, 't' => (string) $row->shift_baru_kode,
                'm' => (string) ($baru['m'] ?? ''), 's' => (string) ($baru['s'] ?? ''),
                'n' => mb_substr(self::s($sel['n'] ?? ''), 0, 120)]],
            [],
            'HRIS:'.$nama,
            ['id' => '', 'name' => $nama, 'adminModules' => ['jadwal']]);
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
        foreach ($this->jadwal->shiftRange($uid, $tgl, $tgl)['rows'] ?? [] as $r) {
            return (array) $r;
        }

        return null;
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

    /** Whether the shift already started (first block bound, work date). */
    private function sudahMulai(string $tgl, array $def): bool
    {
        $blok = $def['blok'] ?? [];
        $mulai = is_array($blok[0] ?? null) ? (string) ($blok[0]['mulai'] ?? '') : (string) ($def['mulai'] ?? '');
        if ($mulai === '') {
            return true;
        }
        $now = Carbon::now('Asia/Jakarta');

        return $tgl < $now->format('Y-m-d')
            || ($tgl === $now->format('Y-m-d') && $now->format('H:i') >= $mulai);
    }

    /** The cell already shows the approved target (recovery after a crash). */
    private function selSamaTarget(?array $sel, HrisUbahShift $row): bool
    {
        if ($sel === null || (string) ($sel['t'] ?? '') !== (string) $row->shift_baru_kode) {
            return false;
        }
        $baru = $row->shift_baru ?? [];
        $def = $this->defs()[(string) $row->shift_baru_kode] ?? null;

        return $this->jamEfektif($sel, $def)[0] === $this->jamEfektif($baru, $def)[0]
            && $this->jamEfektif($sel, $def)[1] === $this->jamEfektif($baru, $def)[1];
    }

    /** Effective hours: explicit cell hours, else the shift definition. */
    private function jamEfektif(array $sel, ?array $def): array
    {
        $m = self::s($sel['m'] ?? '');
        $s = self::s($sel['s'] ?? '');
        if ($m === '' && $def !== null) {
            $m = (string) ($def['mulai'] ?? '');
        }
        if ($s === '' && $def !== null) {
            $s = (string) ($def['selesai'] ?? '');
        }

        return [$m, $s];
    }

    private function assertStatus(string $status): void
    {
        if (! in_array($status, [HrisUbahShift::MENUNGGU, HrisUbahShift::DISETUJUI,
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
