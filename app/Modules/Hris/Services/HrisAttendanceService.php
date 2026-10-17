<?php

declare(strict_types=1);

namespace App\Modules\Hris\Services;

use App\Auth\AccountRepository;
use App\Modules\Absensi\Services\AbsensiService;
use App\Modules\Hris\Models\HrisAudit;
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
 * subject from the token, the MASUK window [mulai - awalMenit, selesai],
 * overnight shifts (a PULANG within 18 h belongs to the MASUK date), the
 * PULANG duration measured from the actual MASUK against its target,
 * queue reasons, and MASUK-wins / PULANG-overwrites.
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

    /** Stored descriptor count for one user (W2): 0 when not enrolled. */
    public function faceCount(string $userId): int
    {
        $row = HrisWajah::query()->where('user_id', $userId)->where('status', 'AKTIF')->first();
        if (! $row || ! is_array($row->descriptor)) {
            return 0;
        }

        return count(FaceMatcher::storedSet(array_values($row->descriptor)) ?? []);
    }

    /**
     * Self-service enrolment (A3, W2): only for yourself, only when not
     * already AKTIF, and the new face must not match anyone else's AKTIF
     * face. Accepts `descriptors` (1–3, guided poses) or the old single
     * `descriptor` (old PWA). `foto` stays the one straight photo. Within
     * the set, two descriptors farther apart than the threshold mean a
     * different person in one enrolment → 422.
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
        $model = mb_substr(self::s($in['model'] ?? ''), 0, 64);
        if ($model === '') {
            throw new HrisError('validation_failed', 'Model wajah wajib diisi (mis. sface-2021dec-int8).', 422);
        }
        if ($this->faceEnrolled($uid)) {
            throw new HrisError('already_enrolled', 'Wajah sudah terdaftar dan langsung aktif. Minta HR mereset untuk daftar ulang.', 409);
        }
        $set = $this->settings->all();
        $ambang = FaceMatcher::ambangUntuk($model, is_array($set['wajahAmbang'] ?? null) ? $set['wajahAmbang'] : []);
        $baru = self::cleanDescriptorSet($in['descriptors'] ?? null, $in['descriptor'] ?? null);
        foreach ($baru as $i => $satu) {
            foreach (array_slice($baru, $i + 1) as $lain) {
                $jarak = ($ambang['metric'] === 'euclidean'
                    ? FaceMatcher::euclidean($satu, $lain)
                    : FaceMatcher::cosineDistance($satu, $lain));
                if ($jarak > (float) $ambang['ambang']) {
                    throw new HrisError('validation_failed',
                        'Sidik wajah dalam satu pendaftaran tidak saling cocok. Ulangi dengan wajah yang sama.', 422);
                }
            }
        }

        // Duplicate check: EVERY new descriptor against EVERY other
        // enrolled person's set of the same model.
        foreach (HrisWajah::query()->where('status', 'AKTIF')->where('user_id', '!=', $uid)->get() as $other) {
            if ((string) $other->model !== $model || ! is_array($other->descriptor)) {
                continue;
            }
            foreach ($baru as $satu) {
                $m = FaceMatcher::match(array_values($other->descriptor), $satu, $ambang);
                if ($m['ok']) {
                    $this->audit->record($nama, 'wajah_duplikat_ditolak', 'hris_wajah', $uid,
                        ['model' => $model, 'skor' => $m['skor'], 'cocok_dengan' => (string) $other->user_id]);
                    throw new HrisError('wajah_milik_orang_lain',
                        'Wajah ini cocok dengan data orang lain. Pendaftaran ditolak dan HR akan menindaklanjuti.', 409);
                }
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
        // A multi-descriptor enrolment stores the set; a legacy single
        // enrolment keeps the flat shape (readers accept both, no migration).
        $tersimpan = count($baru) === 1 && ! array_key_exists('descriptors', $in) ? $baru[0] : $baru;
        $row = HrisWajah::query()->create([
            'user_id' => $uid, 'user_ulid' => null, 'model' => $model,
            'descriptor' => $tersimpan, 'foto_path' => $fotoPath,
            'consent_at' => now(), 'status' => 'AKTIF', 'dibuat_oleh' => mb_substr($nama, 0, 120),
        ]);
        $this->audit->record($nama, 'wajah_didaftarkan', 'hris_wajah', $uid, ['model' => $model]);

        return $row;
    }

    /**
     * Complete your own face (W3): append one descriptor to your AKTIF set,
     * up to 3 total. Accepted only when its smallest distance to the stored
     * set is within the threshold (same model only) and it matches nobody
     * else's. Replacing a face still goes through the HR reset (A3).
     *
     * @return array{user_id:string,jumlah_descriptor:int}
     */
    public function tambahWajah(array $me, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        $nama = self::s($me['name'] ?? '');
        if ($uid === '') {
            throw new HrisError('unauthenticated', 'Login required.', 401);
        }
        $descriptor = self::bersihkanSatu($in['descriptor'] ?? null);
        $model = mb_substr(self::s($in['model'] ?? ''), 0, 64);
        if ($model === '') {
            throw new HrisError('validation_failed', 'Model wajah wajib diisi (mis. sface-2021dec-int8).', 422);
        }
        $row = HrisWajah::query()->where('user_id', $uid)->where('status', 'AKTIF')->first();
        if (! $row || ! is_array($row->descriptor)) {
            throw new HrisError('not_found', 'Wajah belum terdaftar. Daftar dulu sebelum melengkapi.', 404);
        }
        if ((string) $row->model !== $model) {
            throw new HrisError('validation_failed', 'Model sidik baru harus sama dengan yang tersimpan.', 422);
        }
        $set = FaceMatcher::storedSet(array_values($row->descriptor)) ?? [];
        if (count($set) >= 3) {
            throw new HrisError('wajah_lengkap', 'Wajah sudah lengkap (3 sidik). Minta HR mereset untuk ganti wajah.', 409);
        }
        $peta = $this->settings->all()['wajahAmbang'] ?? null;
        $ambang = FaceMatcher::ambangUntuk($model, is_array($peta) ? $peta : []);
        $sendiri = FaceMatcher::match(array_values($row->descriptor), $descriptor, $ambang);
        if (! $sendiri['ok']) {
            throw new HrisError('wajah_tidak_cocok', 'Sidik baru tidak cocok dengan wajah yang tersimpan. Coba foto ulang.', 422);
        }
        foreach (HrisWajah::query()->where('status', 'AKTIF')->where('user_id', '!=', $uid)->get() as $other) {
            if ((string) $other->model !== $model || ! is_array($other->descriptor)) {
                continue;
            }
            $m = FaceMatcher::match(array_values($other->descriptor), $descriptor, $ambang);
            if ($m['ok']) {
                $this->audit->record($nama, 'wajah_duplikat_ditolak', 'hris_wajah', $uid,
                    ['model' => $model, 'skor' => $m['skor'], 'cocok_dengan' => (string) $other->user_id]);
                throw new HrisError('wajah_milik_orang_lain',
                    'Wajah ini cocok dengan data orang lain. Penambahan ditolak dan HR akan menindaklanjuti.', 409);
            }
        }
        $set[] = $descriptor;
        $row->fill(['descriptor' => $set])->save();
        $this->audit->record($nama, 'wajah_dilengkapi', 'hris_wajah', $uid,
            ['model' => $model, 'jumlah_descriptor' => count($set)]);

        return ['user_id' => $uid, 'jumlah_descriptor' => count($set)];
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

    /** Enrolled faces WITHOUT descriptors — HR screen only (W5, W6). */
    public function listFaces(): array
    {
        $sejak = PunchRules::ms() - 7 * 24 * 3600 * 1000;
        $gagal = [];
        foreach (HrisAudit::query()->where('aksi', 'wajah_dicek')->where('waktu', '>=', $sejak)->get(['ref_id', 'data']) as $a) {
            $data = is_array($a->data) ? $a->data : [];
            if (array_key_exists('cocok', $data) && $data['cocok'] === false) {
                $gagal[(string) $a->ref_id] = ($gagal[(string) $a->ref_id] ?? 0) + 1;
            }
        }
        $out = [];
        foreach (HrisWajah::query()->where('status', 'AKTIF')->orderBy('user_id')->get() as $r) {
            $set = is_array($r->descriptor) ? FaceMatcher::storedSet(array_values($r->descriptor)) : null;
            $out[] = ['user_id' => $r->user_id, 'nama' => $this->namaUser($r->user_id),
                'model' => $r->model,
                'consent_at' => $r->consent_at?->toIso8601String(),
                'dibuat_oleh' => $r->dibuat_oleh, 'ada_foto' => $r->foto_path ? true : false,
                'jumlah_descriptor' => $set === null ? 0 : count($set),
                'gagal_cek_7h' => $gagal[(string) $r->user_id] ?? 0,
                'version' => (int) $r->version];
        }

        return $out;
    }

    /**
     * Recent `wajah_dicek` rows for one person (W5) — HR only, newest first.
     *
     * @return array<int,array{waktu:int,skor:float|null,cocok:bool,model:string}>
     */
    public function cekLog(string $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $out = [];
        foreach (HrisAudit::query()->where('aksi', 'wajah_dicek')->where('ref_id', self::s($userId))
            ->orderByDesc('waktu')->orderByDesc('id')->limit($limit)->get() as $a) {
            $data = is_array($a->data) ? $a->data : [];
            $out[] = ['waktu' => (int) $a->waktu,
                'skor' => isset($data['skor']) && is_numeric($data['skor']) ? (float) $data['skor'] : null,
                'cocok' => ! empty($data['cocok']),
                'model' => (string) ($data['model'] ?? '')];
        }

        return $out;
    }

    /**
     * Score percentiles per model over the last 90 days of `wajah_dicek`
     * (W6) — HR only. Nearest-rank percentiles over numeric scores.
     *
     * @return array<string,array{n:int,p50:float,p90:float,p95:float,p99:float}>
     */
    public function kalibrasi(): array
    {
        $sejak = PunchRules::ms() - 90 * 24 * 3600 * 1000;
        $perModel = [];
        foreach (HrisAudit::query()->where('aksi', 'wajah_dicek')->where('waktu', '>=', $sejak)->get(['data']) as $a) {
            $data = is_array($a->data) ? $a->data : [];
            if (! isset($data['skor']) || ! is_numeric($data['skor'])) {
                continue;
            }
            $model = (string) ($data['model'] ?? '');
            if ($model === '') {
                continue;
            }
            $perModel[$model][] = (float) $data['skor'];
        }
        $out = [];
        foreach ($perModel as $model => $skor) {
            sort($skor);
            $n = count($skor);
            $persentil = fn (int $p): float => round($skor[max(0, (int) ceil($p / 100 * $n) - 1)], 4);
            $out[$model] = ['n' => $n, 'p50' => $persentil(50), 'p90' => $persentil(90),
                'p95' => $persentil(95), 'p99' => $persentil(99)];
        }
        ksort($out);

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
        $akurasi = (int) ($in['akurasi'] ?? 0);

        $semuaLokasi = $this->locations(true);
        $dekat = ($lat || $lng) ? PunchRules::nearestLocation($lat, $lng, $semuaLokasi) : null;
        $dalamArea = $dekat
            ? PunchRules::dalamArea($dekat['jarak'], $dekat['lokasi']['radius'], $akurasi, (int) $set['akurasiMaks'])
            : false;
        if ($semuaLokasi === []) {
            // Not set up yet: calling everybody "outside" would queue the whole first day.
            $dalamArea = true;
        }

        $menit = PunchRules::menitWib($now);
        $shift = $this->shiftFor($uid, PunchRules::tglWib($now), $menit);
        $shiftArr = is_array($shift) ? $shift : null;
        $adaShift = $shiftArr !== null && empty($shiftArr['libur']);
        $durasiKerja = $set['durasiKerja'] ?? ['default' => 540, 'orang' => []];

        $tgl = PunchRules::tglWib($now);
        $telat = null;
        $target = null;
        $durasi = null;
        $pulangAwal = false;
        $kurang = 0;
        $luarWaktu = false;
        $dalamShift = false;
        $tanpaMasuk = false;
        $masuk = null;

        if ($arah === 'MASUK') {
            // VALID inside [mulai - awalMenit, selesai]; late inside the
            // shift stays VALID and is only stamped telat_menit.
            if ($adaShift) {
                $dalamShift = PunchRules::masukDalamJendela($menit,
                    $shiftArr['mulai'], $shiftArr['selesai'], (int) $set['awalMenit']);
                $luarWaktu = ! $dalamShift;
                $telat = PunchRules::telatMenit($menit, $shiftArr['mulai'], (int) $set['toleransiTelat']);
            }
            $target = PunchRules::targetDurasiMenit(
                $shiftArr['sumber'] ?? null, $shiftArr['mulai'] ?? '', $shiftArr['selesai'] ?? '',
                $durasiKerja, $uid);
        } else {
            $masuk = HrisKetukan::query()
                ->where('user_id', $uid)->where('arah', 'MASUK')->where('status', '!=', 'DITOLAK')
                ->where('waktu', '>=', $now - 18 * 3600 * 1000)
                ->orderByDesc('waktu')->first();
            if ($masuk === null) {
                $tanpaMasuk = true;
            } else {
                $tgl = PunchRules::workDateForPulang($masuk->tanggal_kerja, PunchRules::tglWib($now));
                // The target comes from the MASUK row's shift snapshot, never
                // a re-read: a roster edited mid-day must not move the goal.
                $target = PunchRules::targetDurasiMenit(
                    $masuk->shift_sumber !== '' ? $masuk->shift_sumber : null,
                    $masuk->shift_mulai, $masuk->shift_selesai, $durasiKerja, $uid);
                $durasi = (int) floor(($now - (int) $masuk->waktu) / 60000);
                $nilai = PunchRules::nilaiPulang($durasi, $target, (int) $set['pulangLewatMenit']);
                $luarWaktu = $nilai['terlambat'];
                $dalamShift = ! $nilai['terlambat'];
                $pulangAwal = $nilai['pulang_awal'];
                $kurang = $nilai['kurang_menit'];
            }
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
        // Whoever was never enrolled always queues WAJAH_KOSONG (A18): the
        // punch screen locks until Daftar Wajah, and the server never lets a
        // stranger through as "unenrolled".
        $wajahHalangi = $w['terdaftar'] ? ! $w['ok'] : true;

        $alasan = PunchRules::tentukanAlasan($dalamArea, $shift,
            $wajahHalangi, $w['terdaftar'], $tanpaMasuk, (bool) $set['tanpaShiftBoleh'], $luarWaktu);
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

        // Suspicion flags (A16): computed now, stored in info, never a queue
        // reason. A PULANG overwrite excludes the row it replaces (the same
        // submission being corrected is not "the previous punch").
        $gantiId = null;
        if ($arah === 'PULANG') {
            $gantiId = HrisKetukan::query()->where('user_id', $uid)
                ->where('tanggal_kerja', $tgl)->where('arah', 'PULANG')->value('id');
        }
        $curiga = $this->tandaCurigaUntuk($uid, $now, $lat, $lng, $akurasi, $dekat,
            $gantiId !== null ? (string) $gantiId : null);

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
        if ($this->kolomInfoAda()) {
            // The info column is new: servers whose owner has not applied the
            // migration yet read live values through shape() instead.
            $data['info'] = [
                'telat_menit' => $telat,
                'pulang_awal' => $pulangAwal,
                'kurang_menit' => $kurang,
                'target_menit' => $target,
                'durasi_menit' => $durasi,
                'curiga' => $curiga,
            ];
        }
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
     * Pre-send face check (A19, W5): {terdaftar, cocok}, no score served.
     * Every check writes a `wajah_dicek` audit row {skor, cocok, model} —
     * no photo, no descriptor. Rate-limited per user by the caller, and the
     * 429 answer never reaches (never audits) here.
     *
     * @return array{terdaftar:bool,cocok:bool}
     */
    public function cekWajah(array $me, array $in): array
    {
        $uid = self::s($me['id'] ?? '');
        if ($uid === '') {
            throw new HrisError('unauthenticated', 'Login required.', 401);
        }
        $descriptor = self::cleanDescriptor($in['descriptor'] ?? null);
        $model = mb_substr(self::s($in['model'] ?? ''), 0, 64);
        if ($model === '') {
            throw new HrisError('validation_failed', 'Model wajah wajib diisi (mis. sface-2021dec-int8).', 422);
        }
        $stored = $this->storedFace($uid);
        if ($stored === null) {
            $this->audit->record(self::s($me['name'] ?? ''), 'wajah_dicek', 'hris_wajah', $uid,
                ['skor' => null, 'cocok' => false, 'model' => $model]);

            return ['terdaftar' => false, 'cocok' => false];
        }
        if ($stored['model'] !== '' && $stored['model'] !== $model) {
            // A descriptor of another model proves nothing either way.
            $this->audit->record(self::s($me['name'] ?? ''), 'wajah_dicek', 'hris_wajah', $uid,
                ['skor' => null, 'cocok' => false, 'model' => $model]);

            return ['terdaftar' => true, 'cocok' => false];
        }
        $set = $this->settings->all();
        $ambang = FaceMatcher::ambangUntuk($stored['model'] !== '' ? $stored['model'] : $model,
            is_array($set['wajahAmbang'] ?? null) ? $set['wajahAmbang'] : []);
        $m = FaceMatcher::match($stored['descriptor'], $descriptor, $ambang);
        $this->audit->record(self::s($me['name'] ?? ''), 'wajah_dicek', 'hris_wajah', $uid,
            ['skor' => $m['skor'], 'cocok' => $m['ok'], 'model' => $model]);

        return ['terdaftar' => true, 'cocok' => $m['ok']];
    }

    /** Whether hris_ketukan.info exists (read-only information_schema check, cached per request). */
    private ?bool $kolomInfo = null;

    private function kolomInfoAda(): bool
    {
        if ($this->kolomInfo !== null) {
            return $this->kolomInfo;
        }
        try {
            $ada = HrisKetukan::query()->getConnection()->selectOne(
                'SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['hris_ketukan', 'info']);

            return $this->kolomInfo = ((int) ($ada->c ?? 0)) > 0;
        } catch (\Throwable) {
            return $this->kolomInfo = false;
        }
    }

    /**
     * Suspicion inputs from the database for one punch (A16): the person's
     * previous punch and whether another user sent the same point within
     * ±5 minutes.
     *
     * @param  array{lokasi:array,jarak:int}|null  $dekat
     * @return array<int,string>
     */
    private function tandaCurigaUntuk(string $uid, int $now, float $lat, float $lng, int $akurasi, ?array $dekat, ?string $kecualiId): array
    {
        $sebelum = HrisKetukan::query()->where('user_id', $uid)->where('waktu', '<', $now)
            ->when($kecualiId !== null, fn ($q) => $q->where('id', '!=', $kecualiId))
            ->orderByDesc('waktu')->first();
        $kembar = HrisKetukan::query()->where('user_id', '!=', $uid)
            ->whereRaw('ABS(`lat` - ?) < 0.000000001', [$lat])
            ->whereRaw('ABS(`lng` - ?) < 0.000000001', [$lng])
            ->where('waktu', '>=', $now - 5 * 60 * 1000)
            ->where('waktu', '<=', $now + 5 * 60 * 1000)
            ->exists();

        return PunchRules::tandaCuriga($lat, $lng, $akurasi,
            $dekat ? ['lat' => (float) $dekat['lokasi']['lat'], 'lng' => (float) $dekat['lokasi']['lng']] : null,
            $sebelum ? ['lat' => (float) $sebelum->lat, 'lng' => (float) $sebelum->lng] : null,
            $kembar);
    }

    /**
     * Punch metrics for shape(): the stored info row, or recomputed live
     * with pure PunchRules helpers when the row (or the server) predates
     * the info column — with curiga [] since flags need punch-time context.
     */
    private function infoUntuk(HrisKetukan $r): array
    {
        $tersimpan = $r->getAttribute('info');
        if (is_array($tersimpan) && $tersimpan !== []) {
            return $tersimpan;
        }

        return $this->hitungInfoLive($r);
    }

    private function hitungInfoLive(HrisKetukan $r): array
    {
        $set = $this->settings->all();
        $kerja = $set['durasiKerja'] ?? ['default' => 540, 'orang' => []];
        if ($r->arah === 'PULANG') {
            $masuk = HrisKetukan::query()
                ->where('user_id', $r->user_id)->where('arah', 'MASUK')->where('status', '!=', 'DITOLAK')
                ->where('waktu', '>=', (int) $r->waktu - 18 * 3600 * 1000)
                ->where('waktu', '<=', (int) $r->waktu)
                ->orderByDesc('waktu')->first();
            if ($masuk === null) {
                return ['telat_menit' => null, 'pulang_awal' => false, 'kurang_menit' => 0,
                    'target_menit' => null, 'durasi_menit' => null, 'curiga' => []];
            }
            $target = PunchRules::targetDurasiMenit(
                $masuk->shift_sumber !== '' ? $masuk->shift_sumber : null,
                $masuk->shift_mulai, $masuk->shift_selesai, $kerja, (string) $r->user_id);
            $durasi = (int) floor(((int) $r->waktu - (int) $masuk->waktu) / 60000);
            $nilai = PunchRules::nilaiPulang($durasi, $target, (int) $set['pulangLewatMenit']);

            return ['telat_menit' => null, 'pulang_awal' => $nilai['pulang_awal'],
                'kurang_menit' => $nilai['kurang_menit'],
                'target_menit' => $target, 'durasi_menit' => $durasi, 'curiga' => []];
        }
        $telat = PunchRules::jamKeMenit($r->shift_mulai) >= 0
            ? PunchRules::telatMenit(PunchRules::menitWib((int) $r->waktu), $r->shift_mulai, (int) $set['toleransiTelat'])
            : null;
        $target = PunchRules::targetDurasiMenit(
            $r->shift_sumber !== '' ? $r->shift_sumber : null,
            $r->shift_mulai, $r->shift_selesai, $kerja, (string) $r->user_id);

        return ['telat_menit' => $telat, 'pulang_awal' => false, 'kurang_menit' => 0,
            'target_menit' => $target, 'durasi_menit' => null, 'curiga' => []];
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
    public function punches(?array $userIds, string $dari, string $sampai, bool $denganCuriga = false): array
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
            $out[] = $this->shape($r, $denganCuriga);
        }

        return ['dari' => $dari, 'sampai' => $sampai, 'ketukan' => $out];
    }

    /** Queued punches, longest-waiting first. @param  array<int,string>|null  $userIds */
    public function queue(?array $userIds, bool $denganCuriga = false): array
    {
        $q = HrisKetukan::query()->where('status', 'MENUNGGU')->orderBy('waktu')->limit(500);
        if ($userIds !== null) {
            $q->whereIn('user_id', $userIds === [] ? ['__tidak_ada__'] : $userIds);
        }
        $out = [];
        foreach ($q->get() as $r) {
            $out[] = $this->shape($r, $denganCuriga);
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
        $set = $this->settings->all();
        // Clock-out goal (A23, A26): from the latest non-rejected MASUK plus
        // its target; null without a MASUK.
        $targetMenit = null;
        $bolehPulang = null;
        foreach (array_reverse($punches) as $p) {
            if ($p['arah'] === 'MASUK' && $p['status'] !== 'DITOLAK') {
                $targetMenit = PunchRules::targetDurasiMenit(
                    $p['shift_sumber'] !== '' ? $p['shift_sumber'] : null,
                    $p['shift_mulai'], $p['shift_selesai'],
                    $set['durasiKerja'] ?? ['default' => 540, 'orang' => []], $uid);
                $bolehPulang = PunchRules::jamWib($p['waktu'] + $targetMenit * 60000);
                break;
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
            'wajahJumlah' => $this->faceCount($uid),
            'lokasi' => $this->locations(true),
            'aturan' => [
                'akurasiMaks' => (int) $set['akurasiMaks'],
                'awalMenit' => (int) $set['awalMenit'],
                'pulangLewatMenit' => (int) $set['pulangLewatMenit'],
                'toleransiTelat' => (int) $set['toleransiTelat'],
            ],
            'targetMenit' => $targetMenit,
            'bolehPulang' => $bolehPulang,
        ];
    }

    /**
     * Public projection of a punch (never the raw photo bytes).
     * curiga[] is HR/head-only: crew (and the puncher's own response) get [].
     */
    public function shape(HrisKetukan $r, bool $denganCuriga = false): array
    {
        $info = $this->infoUntuk($r);
        $curiga = [];
        if ($denganCuriga) {
            foreach ((array) ($info['curiga'] ?? []) as $c) {
                if (is_string($c) && $c !== '') {
                    $curiga[] = $c;
                }
            }
        }

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
            'telat_menit' => $info['telat_menit'] ?? null,
            'pulang_awal' => (bool) ($info['pulang_awal'] ?? false),
            'kurang_menit' => (int) ($info['kurang_menit'] ?? 0),
            'target_menit' => $info['target_menit'] ?? null,
            'durasi_menit' => $info['durasi_menit'] ?? null,
            'curiga' => $curiga,
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
        return self::bersihkanSatu($d);
    }

    /**
     * Normalize enrolment input to a descriptor set (W2): `descriptors`
     * (1–3, each validated like a single descriptor) wins; otherwise the
     * old single `descriptor`. The PWA sends both for old-API compat.
     *
     * @return array<int,array<int,float>>
     */
    private static function cleanDescriptorSet(mixed $banyak, mixed $satu): array
    {
        if ($banyak !== null) {
            if (! is_array($banyak) || $banyak === []) {
                throw new HrisError('validation_failed', 'Sidik wajah tidak lengkap. Coba daftarkan ulang.', 422);
            }
            $banyak = array_values($banyak);
            if (count($banyak) > 3) {
                throw new HrisError('validation_failed', 'Pendaftaran wajah maksimal 3 sidik.', 422);
            }
            $out = [];
            foreach ($banyak as $d) {
                $out[] = self::bersihkanSatu($d);
            }

            return $out;
        }

        return [self::bersihkanSatu($satu)];
    }

    /** @return array<int,float> */
    private static function bersihkanSatu(mixed $d): array
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
