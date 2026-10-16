<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Absensi\Services\AbsensiService;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Models\HrisLokasi;
use App\Modules\Hris\Models\HrisWajah;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\JsonDoc;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * HRIS attendance: faces, punches, the decision queue, locations and the
 * punch-screen bootstrap. Its OWN logic, copied as concepts from
 * AbsensiService (which stays untouched and unused): server time only, the
 * subject from the token, shift window ±minutes, overnight shifts (a PULANG
 * within 18 h belongs to the MASUK date), queue reasons, and MASUK-wins /
 * PULANG-overwrites.
 *
 * Differences from Absensi, all locked in docs/modules/hris.md: the face
 * model is new (descriptors carry their `model`, matched per model), shifts
 * fall back to each person's default shift, HARI_LIBUR comes only from the
 * person's own day off (a public holiday earns PH instead), and enrolment
 * rejects a face that matches anyone else's.
 */
final class HrisAttendanceService
{
    public function __construct(
        private readonly JadwalService $jadwal,
        private readonly HrisSettingsService $settings,
        private readonly HrisAuditService $audit,
        private readonly AbsensiService $absensi,
        private readonly AccountRepository $users,
    ) {}

    /** @var array<string,string> user_id → display name (best-effort, per request) */
    private array $namaCache = [];

    /** @var array<string,string> user_id → division (best-effort, per request) */
    private array $divisiCache = [];

    private function namaUser(string $userId): string
    {
        if (! isset($this->namaCache[$userId])) {
            try {
                $u = $this->users->userById($userId);
                $this->namaCache[$userId] = $u ? (string) ($u['name'] ?? $userId) : $userId;
            } catch (\Throwable) {
                $this->namaCache[$userId] = $userId;
            }
        }

        return $this->namaCache[$userId];
    }

    private function divisiUser(string $userId): string
    {
        if (! isset($this->divisiCache[$userId])) {
            try {
                $this->divisiCache[$userId] = $this->jadwal->divisiUser($userId);
            } catch (\Throwable) {
                $this->divisiCache[$userId] = '';
            }
        }

        return $this->divisiCache[$userId];
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    // ---------------------------------------------------------------- shifts
    //
    // THREE answers, like Absensi: array = shift found, null = genuinely
    // unscheduled, false = the roster did not answer (record, do not queue).
    // On top: when the roster has no cell, fall back to the person's default
    // shift (Jadwal setting shiftKru), marked sumber BAWAAN.

    /** @return array{kode:string,mulai:string,selesai:string,lintas_hari:bool,sumber:string,libur:int}|null|false */
    public function shiftFor(string $userId, string $ymd, ?int $menit = null): array|null|false
    {
        $userId = self::s($userId);
        $ymd = PunchRules::tglValid($ymd);
        if ($userId === '' || $ymd === '') {
            return null;
        }
        try {
            $d = $this->jadwal->shiftRange($userId, $ymd, $ymd);
        } catch (\Throwable) {
            return false;
        }
        foreach ($d['rows'] ?? [] as $r) {
            if ((string) ($r['u'] ?? '') === $userId) {
                $m = (string) ($r['m'] ?? '');
                $s = (string) ($r['s'] ?? '');

                return ['kode' => (string) ($r['t'] ?? ''), 'mulai' => $m, 'selesai' => $s,
                    'lintas_hari' => $m !== '' && $s !== '' && $s <= $m,
                    'sumber' => 'JADWAL', 'libur' => ! empty($r['libur']) ? 1 : 0];
            }
        }

        return $this->defaultShift($userId);
    }

    /** @return array{kode:string,mulai:string,selesai:string,lintas_hari:bool,sumber:string,libur:int}|null|false */
    private function defaultShift(string $userId): array|null|false
    {
        try {
            $set = JsonDoc::toArray($this->jadwal->setting());
        } catch (\Throwable) {
            return false;
        }
        $kru = $set['shiftKru'] ?? [];
        $kode = is_array($kru) ? (string) ($kru[$userId] ?? '') : '';
        if ($kode === '') {
            return null;
        }
        $shifts = $set['shifts'] ?? [];
        $d = (is_array($shifts) && isset($shifts[$kode]) && is_array($shifts[$kode])) ? $shifts[$kode] : [];
        $m = (string) ($d['m'] ?? '');
        $s = (string) ($d['s'] ?? '');
        if ($m === '' || $s === '') {
            return null;
        }

        return ['kode' => $kode, 'mulai' => $m, 'selesai' => $s,
            'lintas_hari' => $s <= $m, 'sumber' => 'BAWAAN',
            'libur' => ! empty($d['libur']) ? 1 : 0];
    }

    // ---------------------------------------------------------------- locations

    /** @return array<int,array{id:string,nama:string,lat:float,lng:float,radius:int,aktif:bool}> */
    public function locations(bool $onlyActive = true): array
    {
        $q = HrisLokasi::query()->orderBy('nama');
        if ($onlyActive) {
            $q->where('aktif', true);
        }
        $out = [];
        foreach ($q->get() as $r) {
            $out[] = ['id' => $r->id, 'nama' => $r->nama,
                'lat' => (float) $r->lat, 'lng' => (float) $r->lng,
                'radius' => (int) $r->radius_m, 'aktif' => (bool) $r->aktif];
        }

        return $out;
    }

    public function saveLocation(array $row): HrisLokasi
    {
        $nama = self::s($row['nama'] ?? '');
        if ($nama === '') {
            throw new HrisError('validation_failed', 'Nama lokasi wajib diisi.', 422);
        }
        $data = [
            'nama' => mb_substr($nama, 0, 120),
            'lat' => (float) ($row['lat'] ?? 0),
            'lng' => (float) ($row['lng'] ?? 0),
            'radius_m' => PunchRules::clampRadius($row['radius'] ?? 120),
            'aktif' => ! empty($row['aktif']),
        ];
        $id = self::s($row['id'] ?? '');
        $lok = $id !== '' ? HrisLokasi::query()->find($id) : null;
        if ($lok) {
            $lok->fill($data)->save();
        } else {
            $lok = HrisLokasi::query()->create($data);
        }

        return $lok;
    }

    public function deleteLocation(string $id): bool
    {
        $lok = HrisLokasi::query()->find(self::s($id));
        if (! $lok) {
            return false;
        }

        return (bool) $lok->delete();
    }

    /**
     * One-time import of the legacy absensi locations as a starting point
     * (A5). Idempotent by nama: re-running updates the same rows.
     */
    public function importLocations(string $by): array
    {
        $dibuat = 0;
        $diperbarui = 0;
        foreach ($this->absensi->locations(false) as $l) {
            $ada = HrisLokasi::query()->where('nama', self::s($l['nama'] ?? ''))->first();
            $data = ['lat' => (float) ($l['lat'] ?? 0), 'lng' => (float) ($l['lng'] ?? 0),
                'radius_m' => PunchRules::clampRadius($l['radius'] ?? 120),
                'aktif' => ! empty($l['aktif'])];
            if ($ada) {
                $ada->fill($data)->save();
                $diperbarui++;
            } else {
                HrisLokasi::query()->create(['nama' => mb_substr(self::s($l['nama'] ?? ''), 0, 120)] + $data);
                $dibuat++;
            }
        }
        $this->audit->record($by, 'impor_lokasi', 'hris_lokasi', '', ['dibuat' => $dibuat, 'diperbarui' => $diperbarui]);

        return ['dibuat' => $dibuat, 'diperbarui' => $diperbarui];
    }

    // ---------------------------------------------------------------- faces

    /** @return array{descriptor:array,model:string}|null */
    private function storedFace(string $userId): ?array
    {
        $row = HrisWajah::query()->where('user_id', $userId)->where('status', 'AKTIF')->first();
        if (! $row || ! is_array($row->descriptor)) {
            return null;
        }

        return ['descriptor' => array_values($row->descriptor), 'model' => (string) $row->model];
    }

    public function faceEnrolled(string $userId): bool
    {
        return HrisWajah::query()->where('user_id', $userId)->where('status', 'AKTIF')->exists();
    }

    /**
     * Self-service enrolment (A3): only for yourself, only when not already
     * AKTIF, and the new face must not match anyone else's AKTIF face.
     */
    public function enrol(array $me, array $in): HrisWajah
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        if ($uid === '') {
            throw new HrisError('unauthenticated', 'Login required.', 401);
        }
        if (empty($in['consent'])) {
            throw new HrisError('validation_failed', 'Persetujuan biometrik wajib dicentang sekali sebelum daftar wajah.', 422);
        }
        $descriptor = self::cleanDescriptor($in['descriptor'] ?? null);
        $model = mb_substr(self::s($in['model'] ?? ''), 0, 64);
        if ($model === '') {
            throw new HrisError('validation_failed', 'Model wajah wajib diisi (mis. sface-2021dec-int8).', 422);
        }
        if ($this->faceEnrolled($uid)) {
            throw new HrisError('already_enrolled', 'Wajah sudah terdaftar dan langsung aktif. Minta HR mereset untuk daftar ulang.', 409);
        }

        // Duplicate check against EVERY enrolled face of the same model.
        $set = $this->settings->all();
        $ambang = FaceMatcher::ambangUntuk($model, is_array($set['wajahAmbang'] ?? null) ? $set['wajahAmbang'] : []);
        foreach (HrisWajah::query()->where('status', 'AKTIF')->where('user_id', '!=', $uid)->get() as $other) {
            if ((string) $other->model !== $model || ! is_array($other->descriptor)) {
                continue;
            }
            $m = FaceMatcher::match(array_values($other->descriptor), $descriptor, $ambang);
            if ($m['ok']) {
                $this->audit->record($nama, 'wajah_duplikat_ditolak', 'hris_wajah', $uid,
                    ['model' => $model, 'skor' => $m['skor'], 'cocok_dengan' => (string) $other->user_id]);
                throw new HrisError('wajah_milik_orang_lain',
                    'Wajah ini cocok dengan data orang lain. Pendaftaran ditolak dan HR akan menindaklanjuti.', 409);
            }
        }

        // The enrolment photo is HR's audit trail (A3): no photo, no face.
        if (! is_string($in['foto'] ?? null) || trim($in['foto']) === '') {
            throw new HrisError('validation_failed', 'Foto wajah wajib diambil dari kamera saat daftar.', 422);
        }

        // Re-enrolment after a reset: prune the old DIRESET rows first (the
        // UNIQUE user_id+status allows one AKTIF and one DIRESET row).
        HrisWajah::query()->where('user_id', $uid)->where('status', 'DIRESET')->delete();
        $fotoPath = self::storePhoto($in['foto']);
        $row = HrisWajah::query()->create([
            'user_id' => $uid, 'user_ulid' => null, 'model' => $model,
            'descriptor' => $descriptor, 'foto_path' => $fotoPath,
            'consent_at' => now(), 'status' => 'AKTIF', 'dibuat_oleh' => mb_substr($nama, 0, 120),
        ]);
        $this->audit->record($nama, 'wajah_didaftarkan', 'hris_wajah', $uid, ['model' => $model]);

        return $row;
    }

    /** HR reset: flip AKTIF to DIRESET so the person may enrol again. */
    public function resetFace(string $by, string $userId): bool
    {
        $userId = self::s($userId);
        $row = HrisWajah::query()->where('user_id', $userId)->where('status', 'AKTIF')->first();
        if (! $row) {
            return false;
        }
        // Keep a single DIRESET row: the UNIQUE user_id+status allows one.
        HrisWajah::query()->where('user_id', $userId)->where('status', 'DIRESET')->delete();
        self::deletePhoto($row->foto_path);
        $row->fill(['status' => 'DIRESET', 'descriptor' => null, 'foto_path' => null])->save();
        $this->audit->record($by, 'wajah_direset', 'hris_wajah', $userId, []);

        return true;
    }

    /** Enrolled faces WITHOUT descriptors — HR screen only. */
    public function listFaces(): array
    {
        $out = [];
        foreach (HrisWajah::query()->where('status', 'AKTIF')->orderBy('user_id')->get() as $r) {
            $out[] = ['user_id' => $r->user_id, 'nama' => $this->namaUser($r->user_id),
                'model' => $r->model,
                'consent_at' => $r->consent_at?->toIso8601String(),
                'dibuat_oleh' => $r->dibuat_oleh, 'ada_foto' => $r->foto_path ? true : false,
                'version' => (int) $r->version];
        }

        return $out;
    }

    public function facePhotoPath(string $userId): ?string
    {
        $row = HrisWajah::query()->where('user_id', $userId)->where('status', 'AKTIF')->first();
        if (! $row || ! $row->foto_path) {
            return null;
        }
        $disk = Storage::disk('local');
        $key = 'hris-faces/'.$row->foto_path;

        return $disk->exists($key) ? $disk->path($key) : null;
    }

    // ---------------------------------------------------------------- punches
    //
    // The SUBJECT always comes from the token and the TIME from the server —
    // never from the request body or the phone clock.

    /** @param  array{id:string,name:string}  $me */
    public function recordPunch(array $me, array $in, ?int $nowMs = null): array
    {
        $set = $this->settings->all();
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        if ($uid === '') {
            throw new HrisError('unauthenticated', 'Login required.', 401);
        }
        $arah = strtoupper(self::s($in['arah'] ?? ''));
        if ($arah !== 'MASUK' && $arah !== 'PULANG') {
            throw new HrisError('validation_failed', 'Arah absen harus MASUK atau PULANG.', 422);
        }
        $now = $nowMs ?? PunchRules::ms();
        $lat = (float) ($in['lat'] ?? 0);
        $lng = (float) ($in['lng'] ?? 0);

        $semuaLokasi = $this->locations(true);
        $dekat = ($lat || $lng) ? PunchRules::nearestLocation($lat, $lng, $semuaLokasi) : null;
        $dalamArea = $dekat ? ($dekat['jarak'] <= $dekat['lokasi']['radius']) : false;
        if ($semuaLokasi === []) {
            // Not set up yet: calling everybody "outside" would queue the whole first day.
            $dalamArea = true;
        }

        $menit = PunchRules::menitWib($now);
        $shift = $this->shiftFor($uid, PunchRules::tglWib($now), $menit);
        $dalamShift = false;
        if (is_array($shift) && empty($shift['libur'])) {
            $dalamShift = PunchRules::dalamRentangShift($menit, $shift['mulai'], $shift['selesai'],
                (int) $set['awalMenit'], (int) $set['akhirMenit']);
        }

        if ($arah === 'PULANG') {
            $masuk = HrisKetukan::query()
                ->where('user_id', $uid)->where('arah', 'MASUK')->where('status', '!=', 'DITOLAK')
                ->where('waktu', '>=', $now - 18 * 3600 * 1000)
                ->orderByDesc('waktu')->first();
            $tgl = PunchRules::workDateForPulang($masuk?->tanggal_kerja, PunchRules::tglWib($now));
        } else {
            $tgl = PunchRules::tglWib($now);
        }

        // ALWAYS matched, even when no descriptor was sent: skipping the check
        // for a missing descriptor lets anyone through as "unenrolled".
        $stored = $this->storedFace($uid);
        $probeModel = mb_substr(self::s($in['model'] ?? ''), 0, 64);
        if ($stored && $probeModel !== '' && $stored['model'] !== '' && $stored['model'] !== $probeModel) {
            // A descriptor of another model proves nothing either way.
            $w = ['skor' => null, 'ok' => false, 'terdaftar' => true];
        } else {
            $ambang = FaceMatcher::ambangUntuk($stored['model'] ?? $probeModel,
                is_array($set['wajahAmbang'] ?? null) ? $set['wajahAmbang'] : []);
            $w = FaceMatcher::match($stored['descriptor'] ?? null, $in['descriptor'] ?? null, $ambang);
        }
        // Whoever IS enrolled MUST match; the unenrolled are only stopped when
        // wajahWajib is on (stopping them by default would lock the whole crew
        // out on day one).
        $wajahHalangi = $w['terdaftar'] ? ! $w['ok'] : ! empty($set['wajahWajib']);

        $alasan = PunchRules::tentukanAlasan($dalamArea, $shift, $dalamShift,
            $wajahHalangi, $w['terdaftar'], (bool) $set['tanpaShiftBoleh']);
        $status = $alasan === '' ? 'VALID' : 'MENUNGGU';
        $alasanKru = mb_substr(trim(self::s($in['alasan'] ?? '')), 0, 255);
        if ($status === 'MENUNGGU' && $alasanKru === '') {
            throw new HrisError('validation_failed',
                'Absen ini di luar ketentuan, jadi harus disertai alasan untuk diajukan.', 422);
        }

        // MASUK: the first one wins. A second MASUK is no error — the crew
        // member tapping twice on a slow signal just needs to be told.
        if ($arah === 'MASUK') {
            $ada = HrisKetukan::query()->where('user_id', $uid)
                ->where('tanggal_kerja', $tgl)->where('arah', 'MASUK')->first();
            if ($ada) {
                return ['duplikat' => true, 'punch' => $this->shape($ada), 'wajahTerdaftar' => $w['terdaftar']];
            }
        }

        $data = [
            'user_id' => $uid, 'user_ulid' => null, 'tanggal_kerja' => $tgl, 'arah' => $arah,
            'waktu' => $now, 'lat' => $lat, 'lng' => $lng, 'akurasi_m' => (int) ($in['akurasi'] ?? 0),
            'lokasi_id' => $dekat ? $dekat['lokasi']['id'] : null,
            'jarak_m' => $dekat ? $dekat['jarak'] : -1, 'dalam_area' => $dalamArea,
            'shift_kode' => is_array($shift) ? mb_substr((string) $shift['kode'], 0, 32) : '',
            'shift_mulai' => is_array($shift) ? (string) $shift['mulai'] : '',
            'shift_selesai' => is_array($shift) ? (string) $shift['selesai'] : '',
            'lintas_hari' => is_array($shift) ? (bool) $shift['lintas_hari'] : false,
            // NONE = genuinely unscheduled, TAK_TERBACA = the roster could not
            // be read then. The difference is only asked about months later,
            // and nothing else stores the answer.
            'shift_sumber' => is_array($shift) ? (string) $shift['sumber'] : ($shift === false ? 'TAK_TERBACA' : 'NONE'),
            'dalam_shift' => $dalamShift,
            'skor_wajah' => isset($w['skor']) && $w['skor'] !== null ? min(9.9999, max(0.0, (float) $w['skor'])) : null,
            'wajah_model' => $probeModel !== '' ? $probeModel : ($stored['model'] ?? ''),
            'wajah_ok' => (bool) $w['ok'],
            'foto_path' => self::storePhoto($in['foto'] ?? null),
            'status' => $status, 'alasan_antrean' => $alasan === '' ? null : [$alasan],
            'alasan_kru' => $alasanKru,
        ];
        if ($arah === 'PULANG') {
            // The last one wins — and a new punch is a new submission, so a
            // previous decision trail is cleared with it.
            $row = HrisKetukan::query()->where('user_id', $uid)
                ->where('tanggal_kerja', $tgl)->where('arah', 'PULANG')->first();
            if ($row) {
                self::deletePhoto($row->foto_path);
                $row->fill($data + [
                    'diputus_oleh' => '', 'diputus_at' => 0, 'nota' => '',
                ])->save();
            } else {
                $row = HrisKetukan::query()->create($data);
            }
        } else {
            try {
                $row = HrisKetukan::query()->create($data);
            } catch (QueryException $e) {
                // Two MASUK taps landing together: UNIQUE(user, date, arah)
                // lets one in; the other gets the same answer as any repeat.
                $ada = HrisKetukan::query()->where('user_id', $uid)
                    ->where('tanggal_kerja', $tgl)->where('arah', 'MASUK')->first();
                if (! $ada) {
                    throw $e;
                }
                self::deletePhoto($data['foto_path']);

                return ['duplikat' => true, 'punch' => $this->shape($ada), 'wajahTerdaftar' => $w['terdaftar']];
            }
        }

        return ['duplikat' => false, 'punch' => $this->shape($row->fresh()), 'wajahTerdaftar' => $w['terdaftar']];
    }

    /**
     * HR decides a queued punch. Only while still MENUNGGU with the version
     * the decider saw: two HR staff opening the queue together must be TOLD
     * the second decision landed on an already-decided row.
     *
     * @return array{ok:bool,error?:string,current?:int}
     */
    public function decide(string $id, string $status, string $nota, string $by, int $berubah): array
    {
        $status = strtoupper(self::s($status));
        if ($status !== 'VALID' && $status !== 'DITOLAK') {
            throw new HrisError('validation_failed', 'Keputusan hanya boleh VALID atau DITOLAK.', 422);
        }
        $row = HrisKetukan::query()->find(self::s($id));
        if (! $row || $row->status !== 'MENUNGGU') {
            return ['ok' => false, 'error' => 'already_decided'];
        }
        if ((int) $row->version !== $berubah) {
            return ['ok' => false, 'error' => 'konflik', 'current' => (int) $row->version];
        }
        $row->fill(['status' => $status, 'diputus_oleh' => mb_substr($by, 0, 120),
            'diputus_at' => PunchRules::ms(), 'nota' => mb_substr(self::s($nota), 0, 255)])->save();
        $this->audit->record($by, 'ketukan_diputus', 'hris_ketukan', $row->id,
            ['status' => $status, 'user_id' => $row->user_id]);

        return ['ok' => true];
    }

    /** @param  array<int,string>|null  $userIds  null = everyone */
    public function punches(?array $userIds, string $dari, string $sampai): array
    {
        $dari = PunchRules::tglValid($dari);
        $sampai = PunchRules::tglValid($sampai);
        if ($dari === '' || $sampai === '') {
            throw new HrisError('validation_failed', 'Perlu from & to (YYYY-MM-DD).', 422);
        }
        if ($sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $q = HrisKetukan::query()->whereBetween('tanggal_kerja', [$dari, $sampai])->orderBy('tanggal_kerja')->orderBy('waktu');
        if ($userIds !== null) {
            $q->whereIn('user_id', $userIds === [] ? ['__tidak_ada__'] : $userIds);
        }
        $out = [];
        foreach ($q->get() as $r) {
            $out[] = $this->shape($r);
        }

        return ['dari' => $dari, 'sampai' => $sampai, 'ketukan' => $out];
    }

    /** Queued punches, longest-waiting first. @param  array<int,string>|null  $userIds */
    public function queue(?array $userIds): array
    {
        $q = HrisKetukan::query()->where('status', 'MENUNGGU')->orderBy('waktu')->limit(500);
        if ($userIds !== null) {
            $q->whereIn('user_id', $userIds === [] ? ['__tidak_ada__'] : $userIds);
        }
        $out = [];
        foreach ($q->get() as $r) {
            $out[] = $this->shape($r);
        }

        return $out;
    }

    public function punchPhotoPath(string $id): ?string
    {
        $row = HrisKetukan::query()->find(self::s($id));
        if (! $row || ! $row->foto_path) {
            return null;
        }
        $disk = Storage::disk('local');
        $key = 'hris-faces/'.$row->foto_path;

        return $disk->exists($key) ? $disk->path($key) : null;
    }

    /** The one-call punch-screen bootstrap: shift, today's punches, locations, server time. */
    public function meToday(array $me): array
    {
        $uid = self::s($me['id'] ?? '');
        $now = PunchRules::ms();
        $tgl = PunchRules::tglWib($now);
        $punches = HrisKetukan::query()->where('user_id', $uid)->where('tanggal_kerja', $tgl)
            ->orderBy('waktu')->get()->map(fn ($r) => $this->shape($r))->values()->all();
        $masuk = null;
        $pulang = null;
        foreach ($punches as $p) {
            if ($p['arah'] === 'MASUK') {
                $masuk = $p;
            } else {
                $pulang = $p;
            }
        }

        return [
            'siapa' => ['id' => $uid, 'nama' => self::s($me['name'] ?? '')],
            'tgl' => $tgl,
            'waktuServer' => $now,
            'jam' => PunchRules::jamWib($now),
            'shift' => $this->shiftFor($uid, $tgl, PunchRules::menitWib($now)),
            'masuk' => $masuk,
            'pulang' => $pulang,
            'wajahTerdaftar' => $this->faceEnrolled($uid),
            'lokasi' => $this->locations(true),
        ];
    }

    /** Public projection of a punch (never the raw photo bytes). */
    public function shape(HrisKetukan $r): array
    {
        return [
            'id' => $r->id, 'user_id' => $r->user_id, 'nama' => $this->namaUser($r->user_id),
            'divisi' => $this->divisiUser($r->user_id),
            'tanggal_kerja' => $r->tanggal_kerja,
            'arah' => $r->arah, 'waktu' => (int) $r->waktu,
            'jam' => PunchRules::jamWib((int) $r->waktu),
            'lat' => (float) $r->lat, 'lng' => (float) $r->lng, 'akurasi_m' => (int) $r->akurasi_m,
            'lokasi_id' => $r->lokasi_id, 'jarak_m' => (int) $r->jarak_m,
            'dalam_area' => (bool) $r->dalam_area,
            'shift_kode' => $r->shift_kode, 'shift_mulai' => $r->shift_mulai,
            'shift_selesai' => $r->shift_selesai, 'lintas_hari' => (bool) $r->lintas_hari,
            'shift_sumber' => $r->shift_sumber, 'dalam_shift' => (bool) $r->dalam_shift,
            'skor_wajah' => $r->skor_wajah === null ? null : (float) $r->skor_wajah,
            'wajah_ok' => (bool) $r->wajah_ok,
            'status' => $r->status, 'alasan_antrean' => $r->alasan_antrean ?? [],
            'alasan_kru' => $r->alasan_kru,
            'diputus_oleh' => $r->diputus_oleh, 'diputus_at' => (int) $r->diputus_at,
            'nota' => $r->nota, 'berubah' => (int) $r->version,
            'ada_foto' => $r->foto_path ? true : false,
            'foto_url' => $r->foto_path ? "/api/v1/hris/punches/{$r->id}/photo" : null,
        ];
    }

    // ---------------------------------------------------------------- photos
    //
    // Face and audit photos live private under storage/app/hris-faces (never
    // public, never logged). The client sends base64 (EXIF GPS already
    // stripped on device); the server stores bytes under a made-up key.

    public static function storePhoto(mixed $foto): ?string
    {
        $foto = is_string($foto) ? trim($foto) : '';
        if ($foto === '') {
            return null;
        }
        if (str_starts_with($foto, 'data:')) {
            $pos = strpos($foto, ',');
            $foto = $pos === false ? '' : substr($foto, $pos + 1);
        }
        $bytes = base64_decode($foto, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            throw new HrisError('validation_failed', 'Foto tidak valid (base64, maks 5 MB).', 422);
        }
        $key = 'foto_'.strtolower((string) Str::ulid()).'.jpg';
        Storage::disk('local')->put('hris-faces/'.$key, $bytes);

        return $key;
    }

    public static function deletePhoto(?string $key): void
    {
        if ($key) {
            Storage::disk('local')->delete('hris-faces/'.$key);
        }
    }

    /** @return array<int,float> */
    private static function cleanDescriptor(mixed $d): array
    {
        if (! is_array($d)) {
            throw new HrisError('validation_failed', 'Sidik wajah tidak lengkap. Coba daftarkan ulang.', 422);
        }
        $d = array_values($d);
        if (count($d) < 32 || count($d) > 1024) {
            throw new HrisError('validation_failed', 'Sidik wajah tidak lengkap. Coba daftarkan ulang.', 422);
        }
        $out = [];
        foreach ($d as $v) {
            if (! is_numeric($v)) {
                throw new HrisError('validation_failed', 'Sidik wajah berisi nilai bukan angka.', 422);
            }
            $out[] = (float) $v;
        }

        return $out;
    }
}
