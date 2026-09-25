<?php

namespace App\Modules\Absensi\Services;

use App\Modules\Account\Services\AccountService;
use App\Modules\Dw\Services\DwService;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Legacy\Sesi;
use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Absensi — port of absensi-mysql/lib_absensi_mysql.php.
 *
 * One sentence for the whole file, like the legacy header: only PUNCHES are
 * stored; lateness, overtime and work duration are never stored, always
 * recomputed from punches + shifts (see recap()/hitungHari()).
 *
 * Behaviour is kept identical to the legacy lib: validation order, error
 * messages, what is written, and the three-state shift lookup (array = shift
 * found, null = genuinely unscheduled, false = the roster module did not
 * answer — which must NOT queue the punch). Runtime DDL (pastikan_tabel /
 * pastikan_kolom) is NOT ported: the tables already exist live.
 *
 * Cross-module HTTP became in-process calls: account login via
 * AccountService::login(), crew shifts via JadwalService::shiftRange(), daily
 * worker shifts via DwService::scheduleRange().
 *
 * Tables (connection `legacy_absensi`): abs_lokasi (PK id), abs_wajah (PK
 * subjek 'USER:<id>'/'DW:<id>'), abs_punch (PK id, UNIQUE
 * subjek_tipe+subjek_id+tgl+arah), abs_setting (id = 1 blob).
 */
class AbsensiService
{
    public function __construct(
        private readonly AccountService $account,
        private readonly JadwalService $jadwal,
        private readonly DwService $dw,
    ) {}

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    private static function ms(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private static function pot(mixed $v, int $n): string
    {
        return mb_substr(self::s($v), 0, $n);
    }

    private static function uid(string $p = 'p'): string
    {
        return $p.dechex(time()).bin2hex(random_bytes(5));
    }

    private function db(): ConnectionInterface
    {
        return Modules::db('absensi');
    }

    // ---------------------------------------------------------------- time (WIB)
    //
    // WIB is explicit, never the server zone: shared hosting often runs UTC,
    // and lateness computed 7 hours off is a failure that shows no error.

    public static function wibMs(?int $epochMs = null): int
    {
        return ($epochMs ?? self::ms()) + 7 * 3600 * 1000;
    }

    public static function jamWib(int $epochMs): string
    {
        return gmdate('H:i', (int) floor(self::wibMs($epochMs) / 1000));
    }

    public static function tglWib(int $epochMs): string
    {
        return gmdate('Y-m-d', (int) floor(self::wibMs($epochMs) / 1000));
    }

    public static function menitWib(int $epochMs): int
    {
        $d = (int) floor(self::wibMs($epochMs) / 1000);

        return ((int) gmdate('H', $d)) * 60 + ((int) gmdate('i', $d));
    }

    public static function jamKeMenit(mixed $hhmm): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', self::s($hhmm), $m)) {
            return -1;
        }

        return ((int) $m[1]) * 60 + ((int) $m[2]);
    }

    /** DATE columns silently turn '2026-13-45' into 0000-00-00 — validate first. */
    public static function tglValid(mixed $v): string
    {
        $v = self::s($v);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    // ---------------------------------------------------------------- gates
    //
    // Same questions as pemanggil_boleh()/pemanggil_admin()/pemanggil_hr():
    // "may open", "may administer", "may decide for others". An empty hr list
    // means module admins only — never everybody.

    public static function boleh(?array $u): bool
    {
        return Sesi::hasModule($u, 'absensi');
    }

    public static function isAdmin(?array $u): bool
    {
        return Sesi::isModuleAdmin($u, 'absensi');
    }

    public function isHr(?array $u): bool
    {
        if (! $u) {
            return false;
        }
        if (self::isAdmin($u)) {
            return true;
        }
        $hr = $this->setting()['hr'] ?? [];

        return is_array($hr) && in_array(self::s($u['id']), $hr, true);
    }

    // ---------------------------------------------------------------- identity
    //
    // masuk is forwarded to the account backend server-to-server, like the
    // legacy teruskan_masuk(): the account API never opens CORS to the
    // absensi subdomain, and the account URL lives in ONE place. Here the
    // "forward" is an in-process AccountService::login() call.

    public function loginMasuk(mixed $nama, mixed $pin): array
    {
        $nama = self::s($nama);
        $pin = self::s($pin);
        if ($nama === '' || $pin === '') {
            throw new RuntimeException('Nama dan PIN wajib diisi.');
        }
        $d = $this->account->login($nama, $pin);
        if (empty($d['ok']) || empty($d['user'])) {
            $e = $d['error'] ?? '';
            if ($e === 'invalid') {
                throw new RuntimeException('Nama atau PIN salah.');
            }
            if ($e === 'missing') {
                throw new RuntimeException('Nama dan PIN wajib diisi.');
            }
            throw new RuntimeException('Tidak bisa masuk'.($e ? ': '.$e : '.'));
        }
        $u = $d['user'];
        if (! self::boleh($u)) {
            throw new RuntimeException('Akun ini belum diberi akses modul Absensi. Minta admin membukanya di Office.');
        }
        if (empty($u['token'])) {
            throw new RuntimeException('Server akun tidak memberi token sesi. Hubungi admin.');
        }

        return $u;
    }

    // ---------------------------------------------------------------- setting

    public static function defaultSetting(): array
    {
        return [
            'toleransiTelat' => 5,
            'awalMenit' => 60,
            'akhirMenit' => 180,
            'lemburMinMenit' => 15,
            'wajahAmbang' => 0.45,
            'wajahWajib' => false,
            'tanpaShiftBoleh' => false,
            'hr' => [],
        ];
    }

    /** The single settings blob, defaults merged in — exactly baca_setting(). */
    public function setting(): array
    {
        try {
            $row = $this->db()->selectOne('SELECT `data` FROM `abs_setting` WHERE `id` = 1');
        } catch (\Throwable) {
            return self::defaultSetting();
        }
        $d = ($row && is_string($row->data)) ? json_decode($row->data, true) : null;

        return array_merge(self::defaultSetting(), is_array($d) ? $d : []);
    }

    public function saveSetting(mixed $data, string $by): array
    {
        $bersih = array_merge(self::defaultSetting(), is_array($data) ? $data : []);
        $this->db()->statement(
            'INSERT INTO `abs_setting` (`id`,`data`,`updated_at`,`updated_by`) VALUES (1,?,?,?)'.
            ' ON DUPLICATE KEY UPDATE `data`=VALUES(`data`),`updated_at`=VALUES(`updated_at`),`updated_by`=VALUES(`updated_by`)',
            [json_encode($bersih, JSON_UNESCAPED_UNICODE), self::ms(), self::s($by)]
        );

        return $bersih;
    }

    // ---------------------------------------------------------------- locations

    /** @return array<int,array{id:string,nama:string,lat:float,lng:float,radius:int,aktif:int}> */
    public function locations(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM `abs_lokasi`'.($onlyActive ? ' WHERE `aktif` = 1' : '').' ORDER BY `nama`';
        $out = [];
        foreach ($this->db()->select($sql) as $r) {
            $out[] = ['id' => $r->id, 'nama' => $r->nama,
                'lat' => (float) $r->lat, 'lng' => (float) $r->lng,
                'radius' => (int) $r->radius_m, 'aktif' => (int) $r->aktif ? 1 : 0];
        }

        return $out;
    }

    /** Radius clamped to 30–2000 m: phone GPS indoors is rarely better than 30 m. */
    public static function clampRadius(mixed $v): int
    {
        return max(30, min(2000, (int) $v));
    }

    public function saveLocation(mixed $row, string $by): string
    {
        $row = is_array($row) ? $row : [];
        $id = self::s($row['id'] ?? '');
        if ($id === '') {
            $id = self::uid('loc');
        }
        $nama = trim(self::s($row['nama'] ?? ''));
        if ($nama === '') {
            throw new RuntimeException('Nama lokasi wajib diisi.');
        }
        $this->db()->statement(
            'INSERT INTO `abs_lokasi` (`id`,`nama`,`lat`,`lng`,`radius_m`,`aktif`,`updated_at`,`updated_by`)'.
            ' VALUES (?,?,?,?,?,?,?,?)'.
            ' ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`),`lat`=VALUES(`lat`),`lng`=VALUES(`lng`),'.
            '`radius_m`=VALUES(`radius_m`),`aktif`=VALUES(`aktif`),'.
            '`updated_at`=VALUES(`updated_at`),`updated_by`=VALUES(`updated_by`)',
            [$id, $nama,
                (float) ($row['lat'] ?? 0), (float) ($row['lng'] ?? 0),
                self::clampRadius($row['radius'] ?? 120),
                empty($row['aktif']) ? 0 : 1,
                self::ms(), self::s($by)]
        );

        return $id;
    }

    public function deleteLocation(mixed $id): int
    {
        return $this->db()->delete('DELETE FROM `abs_lokasi` WHERE `id` = ?', [self::s($id)]);
    }

    /**
     * Earth distance in metres (haversine). Good to hundreds of kilometres;
     * its error is far below phone GPS accuracy, so a fancier formula would
     * not change a single decision here.
     */
    public static function jarakMeter(mixed $lat1, mixed $lng1, mixed $lat2, mixed $lng2): int
    {
        $R = 6371000.0;
        $p1 = deg2rad((float) $lat1);
        $p2 = deg2rad((float) $lat2);
        $dp = deg2rad((float) $lat2 - (float) $lat1);
        $dl = deg2rad((float) $lng2 - (float) $lng1);
        $a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);

        return (int) round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Nearest registered work location. null when none is registered yet —
     * distinct from "far from every location": the first means the module is
     * not set up, the second means the crew really is outside.
     *
     * @param  array<int,array{lat:float,lng:float}>  $locations
     */
    public static function lokasiTerdekat(mixed $lat, mixed $lng, array $locations): ?array
    {
        $best = null;
        foreach ($locations as $l) {
            if ((float) ($l['lat'] ?? 0) == 0 && (float) ($l['lng'] ?? 0) == 0) {
                continue;
            }
            $j = self::jarakMeter($lat, $lng, $l['lat'], $l['lng']);
            if ($best === null || $j < $best['jarak']) {
                $best = ['lokasi' => $l, 'jarak' => $j];
            }
        }

        return $best;
    }

    // ---------------------------------------------------------------- faces

    public static function subjectKey(mixed $tipe, mixed $id): string
    {
        return strtoupper(self::s($tipe)).':'.self::s($id);
    }

    public function saveFace(mixed $tipe, mixed $id, mixed $nama, mixed $descriptor, mixed $foto, string $by): bool
    {
        $descriptor = is_array($descriptor) ? array_values($descriptor) : null;
        if (! is_array($descriptor) || count($descriptor) !== 128) {
            throw new RuntimeException('Sidik wajah tidak lengkap (harus 128 angka). Coba daftarkan ulang.');
        }
        foreach ($descriptor as $v) {
            if (! is_numeric($v)) {
                throw new RuntimeException('Sidik wajah berisi nilai bukan angka.');
            }
        }
        $this->db()->statement(
            'INSERT INTO `abs_wajah` (`subjek`,`nama`,`descriptor`,`foto`,`aktif`,`daftar_at`,`daftar_oleh`)'.
            ' VALUES (?,?,?,?,1,?,?)'.
            ' ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`),`descriptor`=VALUES(`descriptor`),'.
            '`foto`=VALUES(`foto`),`aktif`=1,`daftar_at`=VALUES(`daftar_at`),`daftar_oleh`=VALUES(`daftar_oleh`)',
            [self::subjectKey($tipe, $id), self::s($nama),
                json_encode(array_map('floatval', $descriptor)),
                ($foto ? self::s($foto) : null),
                self::ms(), self::s($by)]
        );

        return true;
    }

    public function deleteFace(mixed $tipe, mixed $id): int
    {
        return $this->db()->delete('DELETE FROM `abs_wajah` WHERE `subjek` = ?', [self::subjectKey($tipe, $id)]);
    }

    /**
     * Registered faces WITHOUT descriptors — the HR screen needs to know who
     * is/ is not registered; the descriptors themselves never leave the server.
     */
    public function listFaces(): array
    {
        $out = [];
        foreach ($this->db()->select(
            'SELECT `subjek`,`nama`,`aktif`,`daftar_at`,`daftar_oleh`,(`descriptor` IS NOT NULL) AS ada FROM `abs_wajah`'
        ) as $r) {
            $p = explode(':', (string) $r->subjek, 2);
            $out[] = ['tipe' => $p[0], 'id' => $p[1] ?? '', 'nama' => $r->nama,
                'aktif' => (int) $r->aktif ? 1 : 0, 'ada' => (int) $r->ada ? 1 : 0,
                'at' => (int) $r->daftar_at, 'oleh' => $r->daftar_oleh];
        }

        return $out;
    }

    public function faceRegistered(mixed $tipe, mixed $id): bool
    {
        $n = $this->db()->selectOne(
            'SELECT COUNT(*) c FROM `abs_wajah` WHERE `subjek` = ? AND `aktif` = 1 AND `descriptor` IS NOT NULL',
            [self::subjectKey($tipe, $id)]
        );

        return $n ? ((int) $n->c > 0) : false;
    }

    /** Euclidean distance between two 128-float face descriptors. */
    public static function faceDistance(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $d = (float) $a[$i] - (float) $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /** @return array{skor:float,ok:bool,terdaftar:bool} */
    public static function faceMatch(?array $stored, mixed $probe, float $ambang): array
    {
        if (! is_array($stored) || count($stored) !== 128) {
            return ['skor' => 1.0, 'ok' => false, 'terdaftar' => false];
        }
        if (! is_array($probe) || count($probe) !== 128) {
            return ['skor' => 1.0, 'ok' => false, 'terdaftar' => true];
        }
        $skor = self::faceDistance(array_values($stored), array_values($probe));

        return ['skor' => round($skor, 4), 'ok' => $skor <= $ambang, 'terdaftar' => true];
    }

    /**
     * The match runs HERE, not in the browser: shipping stored descriptors to
     * crew phones would let anyone answer "match" from Developer Tools
     * without facing the camera.
     *
     * @return array{skor:float,ok:bool,terdaftar:bool}
     */
    public function matchFace(mixed $tipe, mixed $id, mixed $descriptor): array
    {
        $row = $this->db()->selectOne(
            'SELECT `descriptor` FROM `abs_wajah` WHERE `subjek` = ? AND `aktif` = 1',
            [self::subjectKey($tipe, $id)]
        );
        $stored = ($row && $row->descriptor) ? json_decode($row->descriptor, true) : null;

        return self::faceMatch(is_array($stored) ? $stored : null, $descriptor, (float) $this->setting()['wajahAmbang']);
    }

    // ---------------------------------------------------------------- shifts
    //
    // THREE different answers, and the difference decides whether the whole
    // day's punches land in the HR queue:
    //   array = the shift was found
    //   null  = the module ANSWERED: genuinely unscheduled that day
    //   false = the module did NOT answer: we know nothing — the punch is
    //           still recorded but NOT queued (a neighbour outage must not
    //           send the whole company to HR approval with no reason given).

    /** Minutes from a punch time to one shift range. 0 = inside. */
    public static function jarakKeShift(int $menit, mixed $mulai, mixed $selesai): int
    {
        $a = self::jamKeMenit($mulai);
        $b = self::jamKeMenit($selesai);
        if ($b <= $a) {
            $b += 1440;
        }
        if ($menit >= $a && $menit <= $b) {
            return 0;
        }
        $d1 = ($menit < $a) ? ($a - $menit) : ($menit - $b);
        $d2 = ($menit + 1440 >= $a && $menit + 1440 <= $b) ? 0
            : min(abs($a - ($menit + 1440)), abs(($menit + 1440) - $b));

        return min($d1, $d2);
    }

    /**
     * Is $menit inside the shift range + tolerances? A shift ending at or
     * before its start (18:00–02:00) runs past midnight — without this, every
     * night-shift crew queues for approval daily.
     */
    public static function dalamRentangShift(int $menit, mixed $mulai, mixed $selesai, int $awal, int $akhir): bool
    {
        $m = self::jamKeMenit($mulai);
        $s = self::jamKeMenit($selesai);
        if ($m < 0 || $s < 0) {
            return false;
        }
        if ($s <= $m) {
            $s += 1440;
        }
        $a = $m - $awal;
        $b = $s + $akhir;
        if ($menit >= $a && $menit <= $b) {
            return true;
        }
        $menit2 = $menit + 1440;

        return $menit2 >= $a && $menit2 <= $b;
    }

    /** @return array{kode:string,mulai:string,selesai:string,sumber:string,libur:int}|null|false */
    public function shiftHari(mixed $tipe, mixed $id, mixed $tgl, ?int $menit = null): array|null|false
    {
        $tipe = strtoupper(self::s($tipe));
        $tgl = self::tglValid($tgl);
        if ($tgl === '') {
            return null;
        }

        if ($tipe === 'DW') {
            try {
                $d = $this->dw->scheduleRange($tgl, $tgl);
            } catch (\Throwable) {
                return false;
            }
            $rows = $d['rows'] ?? [];
            if (empty($rows)) {
                return null;
            }
            // One DW may hold SEVERAL shifts a day: pick the range holding
            // the punch minute, else the nearest one.
            $pas = null;
            $pasJarak = null;
            foreach ($rows as $r) {
                if ((string) ($r['dwId'] ?? '') !== self::s($id)) {
                    continue;
                }
                $ini = ['kode' => 'DW', 'mulai' => (string) ($r['m'] ?? ''),
                    'selesai' => (string) ($r['s'] ?? ''), 'sumber' => 'DW', 'libur' => 0];
                if ($menit === null) {
                    return $ini;
                }
                $j = self::jarakKeShift($menit, $ini['mulai'], $ini['selesai']);
                if ($pasJarak === null || $j < $pasJarak) {
                    $pas = $ini;
                    $pasJarak = $j;
                }
            }

            return $pas;
        }

        try {
            $d = $this->jadwal->shiftRange(self::s($id), $tgl, $tgl);
        } catch (\Throwable) {
            return false;
        }
        $rows = $d['rows'] ?? [];
        if (empty($rows)) {
            return null;
        }
        foreach ($rows as $r) {
            if ((string) ($r['u'] ?? '') === self::s($id)) {
                return ['kode' => (string) ($r['t'] ?? ''), 'mulai' => (string) ($r['m'] ?? ''),
                    'selesai' => (string) ($r['s'] ?? ''), 'sumber' => 'ROSTER',
                    'libur' => ! empty($r['libur']) ? 1 : 0];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- punches

    /**
     * The WORK DATE of a PULANG punch, resolved from a previously fetched
     * MASUK row: night-shift crew punch out on the next wall-clock date, but
     * their work day is the date they punched in. Pure so it is unit-tested
     * on fixed inputs; the 18-hour lookup lives in recordPunch().
     */
    public static function workDateForPulang(?array $lastMasuk, string $hariIni): string
    {
        return ($lastMasuk && isset($lastMasuk['tgl'])) ? (string) $lastMasuk['tgl'] : $hariIni;
    }

    /**
     * Which queue reason, if any — the six MENUNGGU reasons in legacy order.
     * Pure so the precedence is unit-tested on fixed inputs.
     *
     * @param  array{kode:string,mulai:string,selesai:string,sumber:string,libur:int}|null|false  $shift
     */
    public static function tentukanSebab(
        bool $dalamArea, array|null|false $shift, bool $dalamShift,
        bool $wajahHalangi, bool $wajahTerdaftar, array $set,
    ): string {
        $sebab = '';
        if (! $dalamArea) {
            $sebab = 'LUAR_AREA';
        } elseif ($shift === false) {
            $sebab = '';
        } elseif (! $shift) {
            $sebab = empty($set['tanpaShiftBoleh']) ? 'TANPA_SHIFT' : '';
        } elseif (! empty($shift['libur'])) {
            $sebab = 'HARI_LIBUR';
        } elseif (! $dalamShift) {
            $sebab = 'LUAR_SHIFT';
        }
        // WAJAH = registered but mismatched (HR suspicion); WAJAH_KOSONG =
        // never enrolled (an admin chore, not suspicion).
        if ($sebab === '' && $wajahHalangi) {
            $sebab = $wajahTerdaftar ? 'WAJAH' : 'WAJAH_KOSONG';
        }

        return $sebab;
    }

    public static function bentukPunch(array $r): ?array
    {
        if (! $r) {
            return null;
        }

        return [
            'id' => $r['id'], 'tipe' => $r['subjek_tipe'], 'uid' => $r['subjek_id'], 'nama' => $r['nama'],
            'tgl' => $r['tgl'], 'arah' => $r['arah'], 'waktu' => (int) $r['waktu'], 'jam' => $r['jam'],
            'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'akurasi' => (int) $r['akurasi_m'],
            'lokasi' => $r['lokasi_id'], 'jarak' => (int) $r['jarak_m'], 'dalamArea' => (int) $r['dalam_area'] ? 1 : 0,
            'wajahSkor' => (float) $r['wajah_skor'], 'wajahOk' => (int) $r['wajah_ok'] ? 1 : 0,
            'shift' => $r['shift_kode'], 'm' => $r['shift_mulai'], 's' => $r['shift_selesai'],
            'sumber' => $r['shift_sumber'], 'dalamShift' => (int) $r['dalam_shift'] ? 1 : 0,
            'status' => $r['status'], 'sebab' => $r['sebab'], 'alasan' => $r['alasan'],
            'putusAt' => (int) $r['putus_at'], 'putusOleh' => $r['putus_oleh'], 'putusNota' => $r['putus_nota'],
        ];
    }

    /**
     * Record one punch. The SUBJECT always comes from the token and the TIME
     * from the server — never from the request body or the phone clock.
     *
     * @param  array{tipe:string,id:string,nama:string,arah:string,lat:float,lng:float,akurasi:int,descriptor:mixed,foto:string,alasan:string}  $p
     */
    public function recordPunch(array $p, ?int $nowMs = null): array
    {
        $set = $this->setting();

        $tipe = strtoupper(self::s($p['tipe'] ?? 'USER'));
        if ($tipe !== 'USER' && $tipe !== 'DW') {
            $tipe = 'USER';
        }
        $id = self::s($p['id'] ?? '');
        $nama = self::s($p['nama'] ?? '');
        $arah = strtoupper(self::s($p['arah'] ?? ''));
        if ($arah !== 'MASUK' && $arah !== 'PULANG') {
            throw new RuntimeException('Arah absen harus MASUK atau PULANG.');
        }
        if ($id === '') {
            throw new RuntimeException('Identitas tidak dikenali.');
        }

        $now = $nowMs ?? self::ms();

        $lat = (float) ($p['lat'] ?? 0);
        $lng = (float) ($p['lng'] ?? 0);
        $akr = (int) ($p['akurasi'] ?? 0);

        $adaLokasi = count($this->locations(true)) > 0;
        $dekat = ($lat || $lng) ? self::lokasiTerdekat($lat, $lng, $this->locations(true)) : null;
        $jarak = $dekat ? $dekat['jarak'] : -1;
        $lokId = $dekat ? (string) $dekat['lokasi']['id'] : '';
        $dalamArea = $dekat ? ($dekat['jarak'] <= $dekat['lokasi']['radius']) : false;
        // No locations registered at all = the module is not set up yet:
        // calling everybody "outside" would queue the whole first day.
        if (! $adaLokasi) {
            $dalamArea = true;
            $jarak = -1;
        }

        // $menit goes along so a DW holding two shifts a day is measured
        // against the shift ringing at punch time, not the morning one.
        $menit = self::menitWib($now);
        $shift = $this->shiftHari($tipe, $id, self::tglWib($now), $menit);
        $dalamShift = false;
        if ($shift && empty($shift['libur'])) {
            $dalamShift = self::dalamRentangShift($menit, $shift['mulai'], $shift['selesai'],
                (int) $set['awalMenit'], (int) $set['akhirMenit']);
        }

        // ALWAYS matched, even when no descriptor was sent: skipping the
        // check for a missing descriptor lets anyone through as "unenrolled".
        $w = $this->matchFace($tipe, $id, $p['descriptor'] ?? null);

        // Whoever IS enrolled MUST match; the unenrolled are only stopped
        // when wajahWajib is on (stopping them by default would lock the
        // whole crew out on day one, and the setting would be switched off
        // for everybody at once).
        $wajahHalangi = $w['terdaftar'] ? ! $w['ok'] : ! empty($set['wajahWajib']);

        $sebab = self::tentukanSebab($dalamArea, $shift, $dalamShift,
            $wajahHalangi, $w['terdaftar'], $set);

        $status = $sebab === '' ? 'VALID' : 'MENUNGGU';
        $alasan = trim(self::s($p['alasan'] ?? ''));
        if ($status === 'MENUNGGU' && $alasan === '') {
            throw new RuntimeException('Absen ini di luar ketentuan, jadi harus disertai alasan untuk diajukan.');
        }

        if ($arah === 'PULANG') {
            $row = $this->db()->selectOne(
                'SELECT `tgl`,`waktu` FROM `abs_punch`'.
                ' WHERE `subjek_tipe` = ? AND `subjek_id` = ? AND `arah` = "MASUK" AND `status` <> "DITOLAK"'.
                ' AND `waktu` >= ? ORDER BY `waktu` DESC LIMIT 1',
                [$tipe, $id, $now - 18 * 3600 * 1000]
            );
            $tgl = self::workDateForPulang($row ? (array) $row : null, self::tglWib($now));
        } else {
            $tgl = self::tglWib($now);
        }

        // MASUK: the first one wins. A second MASUK is no error — the crew
        // member tapping twice on a slow signal just needs to be told.
        if ($arah === 'MASUK') {
            $ada = $this->db()->selectOne(
                'SELECT * FROM `abs_punch` WHERE `subjek_tipe` = ? AND `subjek_id` = ? AND `tgl` = ? AND `arah` = "MASUK"',
                [$tipe, $id, $tgl]
            );
            if ($ada) {
                return ['duplikat' => true, 'punch' => self::bentukPunch((array) $ada)];
            }
        }

        $row = [
            self::uid('ab'), $tipe, $id, $nama, $tgl, $arah,
            $now, self::jamWib($now), $lat, $lng, $akr,
            $lokId, $jarak, $dalamArea ? 1 : 0,
            $w['skor'], $w['ok'] ? 1 : 0,
            $shift ? self::s($shift['kode']) : '', $shift ? self::s($shift['mulai']) : '',
            // NONE = genuinely unscheduled, TAK_TERBACA = the roster could
            // not be read then. The difference is only asked about months
            // later, and nothing else stores the answer.
            $shift ? self::s($shift['selesai']) : '',
            $shift ? self::s($shift['sumber']) : ($shift === false ? 'TAK_TERBACA' : 'NONE'),
            $dalamShift ? 1 : 0, $status, $sebab, $alasan,
            (isset($p['foto']) && $p['foto'] ? self::s($p['foto']) : null), $now,
        ];
        $sql = 'INSERT INTO `abs_punch`'.
            ' (`id`,`subjek_tipe`,`subjek_id`,`nama`,`tgl`,`arah`,`waktu`,`jam`,`lat`,`lng`,`akurasi_m`,'.
            '`lokasi_id`,`jarak_m`,`dalam_area`,`wajah_skor`,`wajah_ok`,`shift_kode`,`shift_mulai`,'.
            '`shift_selesai`,`shift_sumber`,`dalam_shift`,`status`,`sebab`,`alasan`,`foto`,`dibuat_at`)'.
            ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        // PULANG: the last one wins — and a new punch is a new submission,
        // so a previous decision trail is cleared with it.
        if ($arah === 'PULANG') {
            $sql .= ' ON DUPLICATE KEY UPDATE `waktu`=VALUES(`waktu`),`jam`=VALUES(`jam`),'.
                '`lat`=VALUES(`lat`),`lng`=VALUES(`lng`),`akurasi_m`=VALUES(`akurasi_m`),'.
                '`lokasi_id`=VALUES(`lokasi_id`),`jarak_m`=VALUES(`jarak_m`),`dalam_area`=VALUES(`dalam_area`),'.
                '`wajah_skor`=VALUES(`wajah_skor`),`wajah_ok`=VALUES(`wajah_ok`),'.
                '`shift_kode`=VALUES(`shift_kode`),`shift_mulai`=VALUES(`shift_mulai`),'.
                '`shift_selesai`=VALUES(`shift_selesai`),`shift_sumber`=VALUES(`shift_sumber`),'.
                '`dalam_shift`=VALUES(`dalam_shift`),`status`=VALUES(`status`),`sebab`=VALUES(`sebab`),'.
                '`alasan`=VALUES(`alasan`),`foto`=VALUES(`foto`),'.
                '`putus_at`=0,`putus_oleh`="",`putus_nota`=""';
        }
        $this->db()->statement($sql, $row);

        $ada = $this->db()->selectOne(
            'SELECT * FROM `abs_punch` WHERE `subjek_tipe` = ? AND `subjek_id` = ? AND `tgl` = ? AND `arah` = ?',
            [$tipe, $id, $tgl, $arah]
        );

        return ['duplikat' => false, 'punch' => self::bentukPunch((array) $ada),
            'wajahTerdaftar' => $w['terdaftar']];
    }

    // ---------------------------------------------------------------- decisions

    /**
     * HR decides a queued punch. Only while still MENUNGGU: two HR staff
     * opening the queue together must be TOLD the second decision landed on
     * an already-decided row (rowCount 0), not silently believed.
     */
    public function decidePunch(mixed $id, mixed $status, mixed $nota, string $by): bool
    {
        $status = strtoupper(self::s($status));
        if ($status !== 'VALID' && $status !== 'DITOLAK') {
            throw new RuntimeException('Keputusan hanya boleh VALID atau DITOLAK.');
        }
        $n = $this->db()->update(
            'UPDATE `abs_punch` SET `status` = ?, `putus_at` = ?, `putus_oleh` = ?, `putus_nota` = ?'.
            ' WHERE `id` = ? AND `status` = "MENUNGGU"',
            [$status, self::ms(), self::s($by), self::s($nota), self::s($id)]
        );

        return $n > 0;
    }

    /** Queued punches, longest-waiting first — the oldest wait hurts the most. */
    public function queue(): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT * FROM `abs_punch` WHERE `status` = "MENUNGGU" ORDER BY `waktu` ASC LIMIT 500') as $r) {
            $p = self::bentukPunch((array) $r);
            $p['foto'] = $r->foto; // the queue genuinely needs to see the face
            $out[] = $p;
        }

        return $out;
    }

    // ---------------------------------------------------------------- recap
    //
    // Every number is computed HERE, none stored: shifts may be tidied after
    // the fact and approvals may land days later, and a stored number would
    // not follow either. DITOLAK punches count as nothing; MENUNGGU punches
    // still show their numbers, flagged.

    /**
     * One work day: late, overtime, early-leave and duration minutes.
     * Pure on fixed inputs — unit-tested, including the morning-shift
     * past-midnight checkout the old wall-clock guess got wrong.
     *
     * @param  array{jam:string,m:string,s:string,waktu:int,status:string}|null  $masuk
     * @param  array{jam:string,m:string,s:string,waktu:int,status:string}|null  $pulang
     */
    public static function hitungHari(?array $masuk, ?array $pulang, array $set): array
    {
        $out = ['telat' => 0, 'lembur' => 0, 'cepat' => 0, 'durasi' => 0, 'lengkap' => 0];
        $tol = (int) ($set['toleransiTelat'] ?? 0);
        if ($masuk && ($masuk['status'] ?? '') !== 'DITOLAK') {
            $m = self::jamKeMenit($masuk['m'] ?? '');
            if ($m >= 0) {
                $datang = self::jamKeMenit($masuk['jam'] ?? '');
                // Arriving past midnight for a shift that started last night
                // reads as "22 hours early" without this.
                if ($datang + 720 < $m) {
                    $datang += 1440;
                }
                $out['telat'] = max(0, $datang - ($m + $tol));
            }
        }
        if ($masuk && $pulang && ($masuk['status'] ?? '') !== 'DITOLAK' && ($pulang['status'] ?? '') !== 'DITOLAK') {
            $out['durasi'] = max(0, (int) round(((int) $pulang['waktu'] - (int) $masuk['waktu']) / 60000));
            $out['lengkap'] = 1;
            $sel = self::jamKeMenit($masuk['s'] ?? '');
            if ($sel >= 0) {
                $mul = self::jamKeMenit($masuk['m'] ?? '');
                if ($mul >= 0 && $sel <= $mul) {
                    $sel += 1440;
                }
                // The checkout time is derived from the REAL elapsed work,
                // never guessed from its wall-clock reading: guessing
                // "$keluar + 720 < $mul" never fires for morning shifts, so a
                // morning crew leaving past midnight once read as 16.5 hours
                // EARLY instead of 7.5 hours overtime — and the recap feeds pay.
                $datangK = self::jamKeMenit($masuk['jam'] ?? '');
                if ($mul >= 0 && $datangK + 720 < $mul) {
                    $datangK += 1440;
                }
                $keluar = $datangK + (int) $out['durasi'];
                $lem = $keluar - $sel;
                $out['lembur'] = $lem >= (int) ($set['lemburMinMenit'] ?? 0) ? $lem : 0;
                $out['cepat'] = max(0, $sel - $keluar);
            }
        }

        return $out;
    }

    public function recap(mixed $dari, mixed $sampai, mixed $subjekId = '', mixed $tipe = ''): array
    {
        $a = self::tglValid($dari);
        $b = self::tglValid($sampai);
        if ($a === '' || $b === '') {
            throw new RuntimeException('rekap butuh dari & sampai (YYYY-MM-DD)');
        }
        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }
        $set = $this->setting();

        $sql = 'SELECT * FROM `abs_punch` WHERE `tgl` BETWEEN ? AND ?';
        $par = [$a, $b];
        if (self::s($subjekId) !== '') {
            $sql .= ' AND `subjek_id` = ?';
            $par[] = self::s($subjekId);
        }
        if (self::s($tipe) !== '') {
            $sql .= ' AND `subjek_tipe` = ?';
            $par[] = strtoupper(self::s($tipe));
        }
        $sql .= ' ORDER BY `tgl`, `nama`, `arah`';

        $hari = [];
        foreach ($this->db()->select($sql, $par) as $r) {
            $p = self::bentukPunch((array) $r);
            $k = $p['tipe'].'|'.$p['uid'].'|'.$p['tgl'];
            if (! isset($hari[$k])) {
                $hari[$k] = ['tipe' => $p['tipe'], 'uid' => $p['uid'],
                    'nama' => $p['nama'], 'tgl' => $p['tgl'], 'masuk' => null, 'pulang' => null];
            }
            $hari[$k][$p['arah'] === 'MASUK' ? 'masuk' : 'pulang'] = $p;
            if ($p['nama'] !== '') {
                $hari[$k]['nama'] = $p['nama'];
            }
        }
        $out = [];
        foreach ($hari as $h) {
            $h['hitung'] = self::hitungHari($h['masuk'], $h['pulang'], $set);
            $h['shift'] = $h['masuk'] ? $h['masuk']['shift'] : ($h['pulang'] ? $h['pulang']['shift'] : '');
            $h['menunggu'] = (($h['masuk'] && $h['masuk']['status'] === 'MENUNGGU')
                || ($h['pulang'] && $h['pulang']['status'] === 'MENUNGGU')) ? 1 : 0;
            $out[] = $h;
        }

        return ['dari' => $a, 'sampai' => $b, 'hari' => array_values($out), 'setting' => $set];
    }

    // ---------------------------------------------------------------- context
    //
    // The one call that prepares the whole punch screen, so the Absen button
    // is never live before its shift is known. Open: without a session it
    // still serves locations, setting and server time.

    public function context(?array $u, ?int $nowMs = null): array
    {
        $now = $nowMs ?? self::ms();
        $data = ['lokasi' => $this->locations(true), 'setting' => $this->setting(), 'waktuServer' => $now];
        if ($u) {
            $tgl = self::tglWib($now);
            $data['siapa'] = ['id' => self::s($u['id']), 'nama' => self::s($u['name']),
                'keterangan' => self::s($u['keterangan'] ?? ''),
                'boleh' => self::boleh($u) ? 1 : 0,
                'admin' => self::isAdmin($u) ? 1 : 0, 'hr' => $this->isHr($u) ? 1 : 0];
            $data['shift'] = $this->shiftHari('USER', $u['id'], $tgl);
            $data['tgl'] = $tgl;
            $r = $this->recap($tgl, $tgl, self::s($u['id']), 'USER');
            $data['hariIni'] = count($r['hari']) ? $r['hari'][0] : null;
            $data['wajahTerdaftar'] = $this->faceRegistered('USER', $u['id']) ? 1 : 0;
        }

        return $data;
    }

    // ---------------------------------------------------------------- diagnostics

    public function ping(): array
    {
        return ['pong' => true, 'backend' => 'laravel', 'env' => Modules::envLabel(),
            'db' => Modules::databaseName('absensi'), 'ts' => gmdate('c')];
    }

    public function stats(): array
    {
        $out = ['backend' => 'laravel', 'env' => Modules::envLabel(), 'db' => Modules::databaseName('absensi'),
            'lokasi' => 0, 'wajah' => 0, 'punch' => 0, 'antre' => 0, 'ada' => false];
        try {
            $out['lokasi'] = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `abs_lokasi`')->c;
            $out['wajah'] = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `abs_wajah`')->c;
            $out['punch'] = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `abs_punch`')->c;
            $out['antre'] = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `abs_punch` WHERE `status` = "MENUNGGU"')->c;
            $out['ada'] = true;
        } catch (\Throwable) {
        }
        $out['ts'] = gmdate('c');

        return $out;
    }
}
