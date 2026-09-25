<?php

namespace App\Modules\Dw\Services;

use App\Support\Legacy\Sesi;
use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use stdClass;

/**
 * Daily Worker — port of dw-mysql/lib_dw_mysql.php, clusters (1) and (2).
 *
 * Covered here: reads (getAll incl. its expiry sweep, jadwalDW), Pekerja
 * Harian (simpanPekerja/hapusPekerja), permintaan (simpan/putus/tugaskan/
 * hapus), ajuan (simpan/putus/putusBanyak/hapus), the role gates, attendance
 * (simpanHadir), replacement (gantiOrang), payment ticks (tandaiBayar),
 * settings (simpanSetting) and the full wipe (kosongkanSemua).
 *
 * Writes are GRANULAR (one row per call), never a whole-state blob: HR and
 * division heads write at overlapping times and a blob would let the last
 * saver silently erase the others.
 *
 * Behaviour is kept identical to the legacy lib: validation order, error
 * codes/messages, what is written, and the ±1 day overlap check (overnight
 * shifts make yesterday's row still run today). Runtime DDL
 * (pastikan_* / cabut_indeks) is NOT ported: the tables already exist live.
 *
 * Pekerja Harian are never Users: they never log in and are identified by
 * phone number (UNIQUE no_hp, normalised to 08…).
 *
 * Tables: dw_pekerja (PK id, UNIQUE no_hp), dw_ajuan (PK id, plain index
 * idx_ajuan_orang_tgl), dw_permintaan (PK id), dw_setting (id = 1 blob).
 */
class DwService
{
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

    private function db(): ConnectionInterface
    {
        return Modules::db('dw');
    }

    /** DATE columns silently turn '2026-13-45' into 0000-00-00 — validate first. */
    public static function tglValid(mixed $v): string
    {
        $v = self::s($v);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : '';
    }

    public static function jamValid(mixed $v): string
    {
        $v = self::s($v);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '';
    }

    /**
     * Divisi/posisi lists are CSV in ONE column (19 Aug 2026: one person may
     * hold several). Order is kept as sent; the FIRST value is the primary
     * one. Same as legacy csv_daftar().
     *
     * @return array<int,string>
     */
    public static function csvDaftar(mixed $v): array
    {
        $bagian = is_array($v) ? $v : explode(',', (string) $v);
        $out = [];
        foreach ($bagian as $x) {
            $x = trim((string) $x);
            if ($x !== '' && ! in_array($x, $out, true)) {
                $out[] = $x;
            }
        }

        return $out;
    }

    /**
     * The SINGLE value of a possibly-CSV column. Copying "bar,floor" into a
     * one-division ajuan row would make it match no division sheet anywhere —
     * the person silently vanishes from every calendar.
     */
    public static function csvUtama(mixed $v): string
    {
        $d = self::csvDaftar($v);

        return count($d) ? $d[0] : '';
    }

    /**
     * Phone numbers are normalised BEFORE storing or looking up: the same
     * person writes "0812-3456-7890", "+62 812 3456 7890" and "81234567890".
     * Canonical form: digits only, leading '0'.
     */
    public static function hpNormal(mixed $v): string
    {
        $d = preg_replace('/\D+/', '', (string) $v);
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '62')) {
            $d = '0'.substr($d, 2);
        } elseif (! str_starts_with($d, '0')) {
            $d = '0'.$d;
        }

        return substr($d, 0, 20);
    }

    /** Only three payment kinds; anything else falls back to BANK. */
    public static function bayarJenisSah(mixed $v): string
    {
        $v = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $v));

        return in_array($v, ['BANK', 'GOPAY', 'DANA'], true) ? $v : 'BANK';
    }

    private static function idBaru(string $awalan): string
    {
        return $awalan.base_convert((string) self::ms(), 10, 36).random_int(100, 999);
    }

    /** Minutes since 00:00. */
    private static function menitJam(string $v): int
    {
        $p = explode(':', $v);

        return ((int) ($p[0] ?? 0)) * 60 + ((int) ($p[1] ?? 0));
    }

    /**
     * Shift length in minutes. An end at or before the start means past
     * midnight (18:00–02:00 = 480 min, not −960) — same rule as the
     * frontend's durasiJam(), or pay would be computed differently twice.
     */
    private static function durasiMenit(string $m, string $s): int
    {
        $a = self::menitJam($m);
        $b = self::menitJam($s);
        if ($b <= $a) {
            $b += 1440;
        }

        return $b - $a;
    }

    /** ISO date shifted n days. */
    private static function geserHari(string $iso, int $n): string
    {
        $t = strtotime($iso.' 12:00:00');

        return $t === false ? $iso : date('Y-m-d', $t + $n * 86400);
    }

    // ---------------------------------------------------------------- roles
    //
    // Same rules as api.php + dw_hrd()/dw_head()/dw_boleh_lihat()/dw_admin():
    // - admin of dw (or superadmin) decides everything;
    // - hr = setting.hr ids decide; when EMPTY every module holder EXCEPT
    //   division heads decides (heads requesting for themselves must not
    //   approve their own requests);
    // - heads see the full talent pool but never decide (except cancelling
    //   their own division's permintaan, decided per action in api.php).

    public static function isAdmin(?array $u): bool
    {
        return Sesi::isModuleAdmin($u, 'dw');
    }

    public function isHead(?array $u): bool
    {
        if (! $u) {
            return false;
        }
        $h = $u['headDivisi'] ?? [];

        return is_array($h) && count($h) > 0;
    }

    public function isHrd(?array $u): bool
    {
        if (! $u) {
            return false;
        }
        if (self::isAdmin($u)) {
            return true;
        }
        $st = $this->setting();
        $hr = (is_object($st) && isset($st->hr) && is_array($st->hr)) ? $st->hr : [];
        foreach ($hr as $id) {
            if ((string) $id === (string) $u['id']) {
                return true;
            }
        }
        if (count($hr)) {
            return false;
        }

        return ! $this->isHead($u);
    }

    /** HRD or head: controls whether phone and payment fields are returned. */
    public function bolehLihat(?array $u): bool
    {
        return $this->isHrd($u) || $this->isHead($u);
    }

    /**
     * The caller's roles, computed on the server and sent along so screens
     * never re-derive access from module lists and settings.
     */
    public function peran(?array $u): array
    {
        return [
            'hrd' => $this->isHrd($u) ? 1 : 0,
            'head' => $this->isHead($u) ? 1 : 0,
            'lihat' => $this->bolehLihat($u) ? 1 : 0,
            'admin' => self::isAdmin($u) ? 1 : 0,
            'nama' => ($u && isset($u['name'])) ? (string) $u['name'] : '',
            // Divisions this person heads: the screens offer only those when
            // a head requests DW.
            'divisi' => ($u && isset($u['headDivisi']) && is_array($u['headDivisi']))
                ? array_values($u['headDivisi']) : [],
        ];
    }

    /** HRD gate. Throws `tidak_berhak:` otherwise. */
    public function assertHrd(?array $u, string $apa): array
    {
        if (! $this->isHrd($u)) {
            Sesi::rejectForbidden($apa.' hanya bisa dilakukan HRD. Minta admin modul menambahkan Anda di Pengaturan → Hak Akses.');
        }

        return $u;
    }

    /**
     * Request gate: HRD everything, otherwise head of THAT division.
     * Throws `tidak_berhak:` otherwise.
     */
    public function assertMinta(?array $u, string $divisi): array
    {
        if ($this->isHrd($u)) {
            return $u;
        }
        $h = ($u && isset($u['headDivisi']) && is_array($u['headDivisi'])) ? $u['headDivisi'] : [];
        if (! count($h)) {
            Sesi::rejectForbidden('Hanya head divisi dan HRD yang bisa meminta daily worker.');
        }
        if ($divisi !== '' && ! in_array($divisi, $h, true)) {
            Sesi::rejectForbidden('Anda head divisi '.implode(', ', $h)
                .' — permintaan untuk divisi '.$divisi.' harus diajukan head divisi itu.');
        }

        return $u;
    }

    // ---------------------------------------------------------------- reads

    /** The setting blob exactly as stored (objects stay objects). */
    public function setting(): mixed
    {
        try {
            $row = $this->db()->selectOne('SELECT `data` FROM `dw_setting` WHERE `id` = 1');
        } catch (\Throwable) {
            return new stdClass;
        }
        if (! $row || $row->data === null || $row->data === '') {
            return new stdClass;
        }
        $v = json_decode($row->data);

        return $v === null ? new stdClass : $v;
    }

    /**
     * Closes what the date has passed. Requests and assignments nobody ever
     * decided stay MENUNGGU forever and bury the urgent ones, so they are
     * swept to KEDALUWARSA on READ (no reliable cron on this hosting).
     * Idempotent; 'KEDALUWARSA' not 'DITOLAK' — no human refused them. Today
     * is WIB, not UTC. A failed sweep must never break the read.
     */
    public function tutupKedaluwarsa(): void
    {
        if (Modules::isInMaintenance('dw')) {
            return;
        }

        $hariIni = gmdate('Y-m-d', time() + 7 * 3600);
        $now = self::ms();
        try {
            $this->db()->update(
                "UPDATE `dw_permintaan`
                    SET `status`='KEDALUWARSA', `putus_at`=?, `putus_oleh`='(sistem)',
                        `putus_nota`='Tanggalnya lewat tanpa diputuskan'
                  WHERE `status`='MENUNGGU' AND `tgl` < ?",
                [$now, $hariIni]
            );
            $this->db()->update(
                "UPDATE `dw_ajuan`
                    SET `status`='KEDALUWARSA', `putus_at`=?, `putus_oleh`='(sistem)',
                        `putus_nota`='Tanggalnya lewat tanpa diputuskan'
                  WHERE `status`='MENUNGGU' AND `tgl` < ?",
                [$now, $hariIni]
            );
        } catch (\Throwable) {
            // Housekeeping, not correctness — a dead sweep must not kill the module.
        }
    }

    /**
     * The whole screen state: setting, talent pool (ordered by name),
     * assignments (in range OR still MENUNGGU) and head requests (same rule).
     * $penuh = the caller may see phone numbers and payment destinations
     * (HRD or head); everyone else still gets the names — Dashboard and
     * Kalender need them to resolve dw_id — but nothing else.
     */
    public function readAll(string $dari, string $sampai, bool $penuh = true): array
    {
        $this->tutupKedaluwarsa();

        // Per-person history, computed on the SERVER over the WHOLE table:
        // the ajuan sent to the screen are date-limited, so counting on the
        // screen would answer "never used" for last month's regulars.
        $riwayat = [];
        try {
            $awalBulan = gmdate('Y-m-01', time() + 7 * 3600);
            foreach ($this->db()->select(
                'SELECT `dw_id`, MAX(`tgl`) AS terakhir,
                        SUM(CASE WHEN `tgl` >= ? THEN 1 ELSE 0 END) AS bulan_ini
                   FROM `dw_ajuan` WHERE `status`=\'DISETUJUI\'
                  GROUP BY `dw_id`',
                [$awalBulan]
            ) as $r) {
                $riwayat[$r->dw_id] = [
                    'terakhir' => (string) $r->terakhir,
                    'bulanIni' => (int) $r->bulan_ini,
                ];
            }
        } catch (\Throwable) {
            $riwayat = [];
        }

        $pekerja = [];
        foreach ($this->db()->select(
            'SELECT `id`,`nama`,`no_hp`,`gender`,`area`,`bank`,
                    `bayar_jenis`,`bayar_bank`,`bayar_nomor`,`bayar_nama`,`divisi`,`posisi`,
                    `skill`,`status`,`catatan`,`dibuat_at`
               FROM `dw_pekerja` ORDER BY `nama`'
        ) as $r) {
            $baris = [
                'id' => $r->id, 'nama' => $r->nama,
                'gender' => $r->gender, 'area' => $r->area,
                'divisi' => $r->divisi, 'posisi' => $r->posisi,
                'skill' => $r->skill, 'status' => $r->status,
                'dibuatAt' => (int) $r->dibuat_at,
                // '' = never used at all — different from 0 times this month.
                'terakhirKerja' => isset($riwayat[$r->id]) ? $riwayat[$r->id]['terakhir'] : '',
                'kerjaBulanIni' => isset($riwayat[$r->id]) ? $riwayat[$r->id]['bulanIni'] : 0,
            ];
            if ($penuh) {
                $baris['hp'] = $r->no_hp;
                $baris['bank'] = $r->bank;
                $baris['bayarJenis'] = $r->bayar_jenis ?? 'BANK';
                $baris['bayarBank'] = $r->bayar_bank ?? '';
                $baris['bayarNomor'] = $r->bayar_nomor ?? '';
                $baris['bayarNama'] = $r->bayar_nama ?? '';
                $baris['catatan'] = $r->catatan;
            }
            $pekerja[] = $baris;
        }

        $a = self::tglValid($dari);
        $b = self::tglValid($sampai);

        if ($a !== '' && $b !== '') {
            $rowsA = $this->db()->select(
                'SELECT * FROM `dw_ajuan`
                  WHERE (`tgl` BETWEEN ? AND ?) OR `status` = \'MENUNGGU\'
                  ORDER BY `tgl`',
                [$a, $b]
            );
        } else {
            $rowsA = $this->db()->select('SELECT * FROM `dw_ajuan` ORDER BY `tgl`');
        }
        $ajuan = [];
        foreach ($rowsA as $r) {
            $ajuan[] = $this->bentukAjuan((array) $r);
        }

        if ($a !== '' && $b !== '') {
            $rowsM = $this->db()->select(
                'SELECT * FROM `dw_permintaan`
                  WHERE (`tgl` BETWEEN ? AND ?) OR `status` = \'MENUNGGU\'
                  ORDER BY `tgl`',
                [$a, $b]
            );
        } else {
            $rowsM = $this->db()->select('SELECT * FROM `dw_permintaan` ORDER BY `tgl`');
        }
        $minta = [];
        foreach ($rowsM as $r) {
            $minta[] = $this->bentukPermintaan((array) $r);
        }

        return ['setting' => $this->setting(), 'pekerja' => $pekerja,
            'ajuan' => $ajuan, 'permintaan' => $minta];
    }

    public function bentukPermintaan(array $r): array
    {
        return [
            'id' => $r['id'], 'divisi' => $r['divisi'], 'tgl' => $r['tgl'],
            'm' => $r['jam_mulai'], 's' => $r['jam_selesai'],
            'posisi' => $r['posisi'], 'jumlah' => (int) $r['jumlah'],
            'catatan' => $r['catatan'], 'status' => $r['status'],
            'dibuatAt' => (int) $r['dibuat_at'], 'dibuatOleh' => $r['dibuat_oleh'],
            'putusAt' => (int) $r['putus_at'], 'putusOleh' => $r['putus_oleh'],
            'putusNota' => $r['putus_nota'],
            'diubahAt' => isset($r['diubah_at']) ? (int) $r['diubah_at'] : 0,
            'diubahOleh' => isset($r['diubah_oleh']) ? $r['diubah_oleh'] : '',
            'usulan' => (isset($r['usulan']) && self::s($r['usulan']) !== '')
                ? array_values(array_filter(explode(',', $r['usulan'])))
                : [],
        ];
    }

    public function bentukAjuan(array $r): array
    {
        return [
            'id' => $r['id'], 'dwId' => $r['dw_id'], 'tgl' => $r['tgl'],
            'm' => $r['jam_mulai'], 's' => $r['jam_selesai'],
            'divisi' => $r['divisi'], 'posisi' => $r['posisi'],
            'catatan' => $r['catatan'], 'status' => $r['status'],
            'dibuatAt' => (int) $r['dibuat_at'], 'dibuatOleh' => $r['dibuat_oleh'],
            'putusAt' => (int) $r['putus_at'], 'putusOleh' => $r['putus_oleh'],
            'putusNota' => $r['putus_nota'],
            'hadir' => $r['hadir'],
            'hadirNota' => isset($r['hadir_nota']) ? $r['hadir_nota'] : '',
            'hadirOleh' => isset($r['hadir_oleh']) ? $r['hadir_oleh'] : '',
            // Who confirmed and when: attendance decides pay, so this is the
            // only trail to ask back when a number is disputed.
            'hadirAt' => isset($r['hadir_at']) ? (int) $r['hadir_at'] : 0,
            // Link to the head request that birthed it. Without it the
            // Permintaan screen counts "0 of 3" for staffed requests and HR
            // assigns people twice.
            'permintaanId' => isset($r['permintaan_id']) ? $r['permintaan_id'] : '',
        ];
    }

    /**
     * The bridge to Jadwal Shift (and absensi): approved shifts in a range,
     * minimal columns — no phone numbers, no PIN, no HR notes. Only
     * DISETUJUI leaves: showing MENUNGGU on the calendar makes heads believe
     * the gap is filled. Called in-process by the jadwal/absensi ports, and
     * served open over HTTP for the old frontends until they migrate.
     */
    public function scheduleRange(string $dari, string $sampai): array
    {
        $a = self::tglValid($dari);
        $b = self::tglValid($sampai);
        if ($a === '' || $b === '') {
            throw new RuntimeException('jadwalDW butuh dari & sampai (YYYY-MM-DD)');
        }
        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }

        $rows = [];
        foreach ($this->db()->select(
            'SELECT j.`id`, j.`dw_id`, j.`tgl`, j.`jam_mulai`, j.`jam_selesai`,
                    j.`divisi`, j.`posisi`, j.`hadir`, p.`nama`, p.`status` AS st_orang
               FROM `dw_ajuan` j
               LEFT JOIN `dw_pekerja` p ON p.`id` = j.`dw_id`
              WHERE j.`status` = \'DISETUJUI\' AND j.`tgl` BETWEEN ? AND ?
              ORDER BY p.`nama`, j.`tgl`, j.`jam_mulai`',
            [$a, $b]
        ) as $r) {
            $rows[] = [
                'id' => $r->id, 'dwId' => $r->dw_id,
                // An orphaned assignment (worker deleted) still draws with a
                // placeholder name: a cell missing from the calendar is more
                // dangerous than a strange name.
                'nama' => ($r->nama === null || $r->nama === '') ? '(DW dihapus)' : $r->nama,
                'divisi' => $r->divisi, 'posisi' => $r->posisi,
                'tgl' => $r->tgl, 'm' => $r->jam_mulai, 's' => $r->jam_selesai,
                'hadir' => $r->hadir,
            ];
        }

        return ['rows' => $rows, 'dari' => $a, 'sampai' => $b];
    }

    // ---------------------------------------------------------------- pekerja

    /** Upsert a talent-pool worker. Duplicate no_hp answers readably, not with SQLSTATE 23000. */
    public function savePekerja(mixed $row, string $by): array
    {
        $row = is_array($row) ? $row : [];

        $id = self::pot($row['id'] ?? '', 32);
        $nama = self::pot($row['nama'] ?? '', 120);
        $hp = self::hpNormal($row['hp'] ?? '');
        if ($nama === '') {
            throw new RuntimeException('Nama DW wajib diisi');
        }
        if ($hp === '') {
            throw new RuntimeException('No. HP wajib diisi — nomor inilah identitas DW, dan lewat itu HR mengabarinya');
        }

        $bentrok = $this->db()->selectOne('SELECT `id`,`nama` FROM `dw_pekerja` WHERE `no_hp` = ?', [$hp]);
        if ($bentrok && $bentrok->id !== $id) {
            throw new RuntimeException('No. HP '.$hp.' sudah terdaftar atas nama '.$bentrok->nama.'. Sunting data itu, jangan buat baru.');
        }

        $baru = ($id === '');
        if ($baru) {
            $id = self::idBaru('DW');
        }

        // Only two statuses. The old labels were judgement, not fact, and
        // their boundary was never clear. Old rows are mapped, not refused.
        $status = strtoupper(self::pot($row['status'] ?? 'AKTIF', 16));
        if ($status === 'PANTAU') {
            $status = 'AKTIF';
        }
        if ($status === 'BLOKIR') {
            $status = 'NONAKTIF';
        }
        if (! in_array($status, ['AKTIF', 'NONAKTIF'], true)) {
            $status = 'AKTIF';
        }

        // The pin column is never read nor written: the DW login gate is gone
        // and writing secrets nobody uses only leaves secrets waiting to leak.

        $now = self::ms();
        $by = self::pot($by, 120);
        $jenis = self::bayarJenisSah($row['bayarJenis'] ?? '');
        $arg = [
            $id, $nama, $hp,
            self::pot($row['gender'] ?? '', 10),
            self::pot($row['area'] ?? '', 80),
            self::pot($row['bank'] ?? '', 120),
            $jenis,
            // The bank name only counts for bank transfers.
            $jenis === 'BANK' ? self::pot($row['bayarBank'] ?? '', 60) : '',
            self::pot($row['bayarNomor'] ?? '', 60),
            self::pot($row['bayarNama'] ?? '', 120),
            self::pot(implode(',', self::csvDaftar($row['divisi'] ?? '')), 120),
            self::pot(implode(',', self::csvDaftar($row['posisi'] ?? '')), 240),
            self::pot($row['skill'] ?? '', 255),
            $status,
            self::pot($row['catatan'] ?? '', 255),
            $now, $by,
        ];

        if ($baru) {
            $this->db()->statement(
                'INSERT INTO `dw_pekerja`
                   (`id`,`nama`,`no_hp`,`gender`,`area`,`bank`,
                    `bayar_jenis`,`bayar_bank`,`bayar_nomor`,`bayar_nama`,`divisi`,`posisi`,`skill`,
                    `status`,`catatan`,`dibuat_at`,`dibuat_oleh`,`updated_at`,`updated_oleh`)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [...$arg, $now, $by]
            );
        } else {
            $this->db()->statement(
                'UPDATE `dw_pekerja` SET
                   `nama`=?, `no_hp`=?, `gender`=?, `area`=?, `bank`=?,
                   `bayar_jenis`=?, `bayar_bank`=?, `bayar_nomor`=?, `bayar_nama`=?,
                   `divisi`=?, `posisi`=?, `skill`=?, `status`=?, `catatan`=?,
                   `updated_at`=?, `updated_oleh`=?
                 WHERE `id`=?',
                [...array_slice($arg, 1), $id]
            );
        }

        return ['saved' => true, 'id' => $id, 'baru' => $baru];
    }

    /**
     * Hard-deletes the worker but keeps their assignments: worked history is
     * a record of what happened, and dropping it rewrites last month's recap
     * every time HR tidies the list. Orphans draw as "(DW dihapus)".
     */
    public function deletePekerja(mixed $id): array
    {
        $this->db()->delete('DELETE FROM `dw_pekerja` WHERE `id` = ?', [self::s($id)]);

        return ['deleted' => true, 'id' => self::s($id)];
    }

    public function pekerjaById(string $id): ?array
    {
        $r = $this->db()->selectOne('SELECT * FROM `dw_pekerja` WHERE `id` = ?', [self::s($id)]);

        return $r ? (array) $r : null;
    }

    /**
     * Filtered talent-pool listing for v1 (Database DW screen). Same $penuh
     * redaction as getAll. Filters: status (exact, uppercase), divisi (CSV
     * membership), q (substring of nama or no_hp).
     */
    public function listWorkers(array $f, bool $penuh = true): array
    {
        $all = $this->readAll('', '', $penuh)['pekerja'];
        $status = strtoupper(self::s($f['status'] ?? ''));
        $divisi = self::s($f['divisi'] ?? '');
        $q = mb_strtolower(self::s($f['q'] ?? ''));

        return array_values(array_filter($all, function ($p) use ($status, $divisi, $q) {
            if ($status !== '' && strtoupper((string) $p['status']) !== $status) {
                return false;
            }
            if ($divisi !== '' && ! in_array($divisi, self::csvDaftar($p['divisi']), true)) {
                return false;
            }
            if ($q !== '' && ! str_contains(mb_strtolower((string) $p['nama']), $q)
                && ! str_contains(mb_strtolower((string) ($p['hp'] ?? '')), $q)) {
                return false;
            }

            return true;
        }));
    }

    // ---------------------------------------------------------------- ajuan

    /**
     * The ONE overlap checker for every door. Returns the conflicting row or
     * null. DITOLAK/BATAL/KEDALUWARSA don't count — the person is not coming.
     * Only TIME overlap counts (same or different division): since the unique
     * key (dw_id, tgl) was dropped, one person may work two shifts a day.
     * THREE days at once: an overnight shift from yesterday still runs today,
     * so all three are compared on one timeline with per-day offsets.
     * $abaikan = the id of the row being edited — opening an assignment and
     * pressing Save must not conflict with itself.
     */
    public function bentrokAjuanRow(string $dw, string $tgl, string $m, string $sj, string $divisi, string $abaikan = ''): ?array
    {
        $ms = self::menitJam($m);
        $ns = $ms + self::durasiMenit($m, $sj);
        $rows = $this->db()->select(
            'SELECT `id`,`tgl`,`divisi`,`posisi`,`jam_mulai`,`jam_selesai`,`status`
               FROM `dw_ajuan`
              WHERE `dw_id` = ? AND `tgl` IN (?,?,?)
                AND (`status`=\'MENUNGGU\' OR `status`=\'DISETUJUI\')',
            [$dw, $tgl, self::geserHari($tgl, -1), self::geserHari($tgl, 1)]
        );
        foreach ($rows as $T) {
            if ($abaikan !== '' && (string) $T->id === (string) $abaikan) {
                continue;
            }
            $off = ($T->tgl === $tgl) ? 0 : (($T->tgl < $tgl) ? -1440 : 1440);
            $as = self::menitJam($T->jam_mulai) + $off;
            $ae = $as + self::durasiMenit($T->jam_mulai, $T->jam_selesai);
            if (max($as, $ms) < min($ae, $ns)) {
                return [
                    // `id` rides along since 19 Aug 2026: `timpa` overwrites
                    // the conflicting row BY ID — without it "replace" would
                    // birth a second overlapping row, the opposite of asked.
                    'id' => $T->id,
                    'tgl' => $T->tgl, 'divisi' => $T->divisi, 'posisi' => $T->posisi,
                    'm' => $T->jam_mulai, 's' => $T->jam_selesai, 'status' => $T->status,
                    'lintasHari' => ($T->tgl !== $tgl),
                ];
            }
        }

        return null;
    }

    /**
     * Someone ANOTHER head already suggested for an overlapping shift, though
     * nothing is booked yet. Not as hard as a row conflict — nobody is
     * promised — but letting it through guarantees one side fails at assign
     * time, read by HRD instead of the head who caused it.
     * $abaikan = the request being edited.
     */
    public function bentrokUsulan(string $dw, string $tgl, string $m, string $sj, string $divisi, string $abaikan = ''): ?array
    {
        $ms = self::menitJam($m);
        $ns = $ms + self::durasiMenit($m, $sj);
        $rows = $this->db()->select(
            'SELECT `id`,`divisi`,`tgl`,`jam_mulai`,`jam_selesai`,`usulan`,`dibuat_oleh`
               FROM `dw_permintaan`
              WHERE `status`=\'MENUNGGU\' AND `tgl` IN (?,?,?)',
            [$tgl, self::geserHari($tgl, -1), self::geserHari($tgl, 1)]
        );
        foreach ($rows as $P) {
            if ($abaikan !== '' && (string) $P->id === (string) $abaikan) {
                continue;
            }
            $us = ($P->usulan === null || self::s($P->usulan) === '')
                ? [] : array_filter(explode(',', $P->usulan));
            if (! in_array((string) $dw, array_map('strval', $us), true)) {
                continue;
            }
            $off = ($P->tgl === $tgl) ? 0 : (($P->tgl < $tgl) ? -1440 : 1440);
            $as = self::menitJam($P->jam_mulai) + $off;
            $ae = $as + self::durasiMenit($P->jam_mulai, $P->jam_selesai);
            // Same day & same division is not a conflict: the same head
            // rearranging their own request, not two people racing.
            if ($off === 0 && $P->divisi === $divisi) {
                continue;
            }
            if (max($as, $ms) < min($ae, $ns)) {
                return [
                    'tgl' => $P->tgl, 'divisi' => $P->divisi, 'posisi' => '',
                    'm' => $P->jam_mulai, 's' => $P->jam_selesai, 'status' => 'DIUSULKAN',
                    'oleh' => $P->dibuat_oleh, 'lintasHari' => ($off !== 0),
                ];
            }
        }

        return null;
    }

    /**
     * Way 1 — a head points at the person directly for their own division.
     * Status is ALWAYS forced to MENUNGGU, whatever the client sends:
     * otherwise anyone calling the API could approve themselves straight
     * onto the calendar. With `timpa`, overwrites the conflicting row by id
     * (the user already read the confirmation naming the other shift).
     * On overlap returns {saved:false, bentrok} instead of throwing.
     */
    public function saveAjuan(mixed $row, string $by): array
    {
        $row = is_array($row) ? $row : [];

        $dw = self::pot($row['dwId'] ?? '', 32);
        $tgl = self::tglValid($row['tgl'] ?? '');
        $m = self::jamValid($row['m'] ?? '');
        $sj = self::jamValid($row['s'] ?? '');
        if ($dw === '' || $tgl === '') {
            throw new RuntimeException('Ajuan butuh DW dan tanggal');
        }
        if ($m === '' || $sj === '') {
            throw new RuntimeException('Jam mulai dan jam selesai wajib diisi');
        }

        $o = $this->db()->selectOne('SELECT `nama`,`status`,`divisi`,`posisi` FROM `dw_pekerja` WHERE `id` = ?', [$dw]);
        if (! $o) {
            throw new RuntimeException('DW tidak ditemukan: '.$dw);
        }
        if ($o->status === 'NONAKTIF' || $o->status === 'BLOKIR') {
            throw new RuntimeException($o->nama.' berstatus tidak aktif dan tidak bisa dijadwalkan.');
        }

        // Divisi/posisi are COPIED onto the row, not always read from master:
        // next week the same person may be called to another division, and
        // history must not move columns when master changes. csvUtama() is
        // mandatory on both — one ajuan row holds ONE division.
        $divisi = self::pot(isset($row['divisi']) && self::s($row['divisi']) !== ''
            ? self::csvUtama($row['divisi']) : self::csvUtama($o->divisi), 16);
        $posisi = self::pot(isset($row['posisi']) && self::s($row['posisi']) !== ''
            ? self::csvUtama($row['posisi']) : self::csvUtama($o->posisi), 60);

        $timpa = ! empty($row['timpa']);
        $id = self::pot($row['id'] ?? '', 32);
        $B = $this->bentrokAjuanRow($dw, $tgl, $m, $sj, $divisi, $id);
        if ($timpa && $B && $id === '') {
            $id = (string) $B['id'];
        }
        if ($timpa) {
            $B = null;
        }
        if ($B) {
            $out = [
                'nama' => $o->nama,
                'tgl' => $B['tgl'],
                'divisi' => $B['divisi'],      // the division that booked first
                'posisi' => $B['posisi'],
                'm' => $B['m'],
                's' => $B['s'],
                'status' => $B['status'],
                'divisiBaru' => $divisi,
                'mBaru' => $m,
                'sBaru' => $sj,
            ];
            if (! empty($B['lintasHari'])) {
                $out['lintasHari'] = true;
                $out['tglBaru'] = $tgl;
            }

            return ['saved' => false, 'bentrok' => $out];
        }

        if ($id === '') {
            $id = self::idBaru('AJ');
        }

        // ON DUPLICATE KEY now covers ONE key only: the PRIMARY (id), i.e. the
        // same assignment edited. Status stays forced to MENUNGGU on edit: an
        // edited assignment must be re-approved, never silently keep DISETUJUI
        // with different hours.
        $this->db()->statement(
            'INSERT INTO `dw_ajuan`
               (`id`,`dw_id`,`tgl`,`jam_mulai`,`jam_selesai`,`divisi`,`posisi`,`catatan`,
                `status`,`dibuat_at`,`dibuat_oleh`)
             VALUES (?,?,?,?,?,?,?,?,\'MENUNGGU\',?,?)
             ON DUPLICATE KEY UPDATE
               `dw_id`=VALUES(`dw_id`), `tgl`=VALUES(`tgl`),
               `jam_mulai`=VALUES(`jam_mulai`), `jam_selesai`=VALUES(`jam_selesai`),
               `divisi`=VALUES(`divisi`), `posisi`=VALUES(`posisi`),
               `catatan`=VALUES(`catatan`), `status`=\'MENUNGGU\',
               `dibuat_at`=VALUES(`dibuat_at`), `dibuat_oleh`=VALUES(`dibuat_oleh`),
               `putus_at`=0, `putus_oleh`=\'\', `putus_nota`=\'\'',
            [$id, $dw, $tgl, $m, $sj, $divisi, $posisi,
                self::pot($row['catatan'] ?? '', 255),
                self::ms(), self::pot($by, 120)]
        );

        // Re-read BY ID, never by (dw_id, tgl): since double shifts are
        // allowed that pair can match SEVERAL rows, and answering with the
        // morning shift after saving the night one makes Cancel kill wrong.
        $ada = $this->db()->selectOne('SELECT * FROM `dw_ajuan` WHERE `id` = ?', [$id]);

        return ['saved' => true, 'row' => $ada ? $this->bentukAjuan((array) $ada) : null];
    }

    public function decideAjuan(mixed $id, mixed $status, mixed $nota, string $by): array
    {
        $id = self::s($id);
        $status = strtoupper(self::s($status));
        if (! in_array($status, ['DISETUJUI', 'DITOLAK', 'MENUNGGU', 'BATAL'], true)) {
            throw new RuntimeException('Status putusan tidak dikenal: '.$status);
        }
        $n = $this->db()->update(
            'UPDATE `dw_ajuan` SET `status`=?, `putus_at`=?, `putus_oleh`=?, `putus_nota`=?
              WHERE `id`=?',
            [$status, self::ms(), self::pot($by, 120), self::pot($nota, 255), $id]
        );
        if ($n === 0) {
            // rowCount 0 also happens when the status is already exactly that — not an error.
            $ada = $this->db()->selectOne('SELECT 1 FROM `dw_ajuan` WHERE `id` = ?', [$id]);
            if (! $ada) {
                throw new RuntimeException('Ajuan tidak ditemukan: '.$id);
            }
        }

        return ['saved' => true, 'id' => $id, 'status' => $status];
    }

    /**
     * Bulk decision — HR approves a whole day's queue at once. ONE
     * transaction so it never stops halfway leaving half a day mixed.
     */
    public function decideBanyak(mixed $ids, mixed $status, mixed $nota, string $by): array
    {
        if (! is_array($ids) || ! count($ids)) {
            return ['saved' => true, 'jumlah' => 0];
        }
        $status = strtoupper(self::s($status));
        if (! in_array($status, ['DISETUJUI', 'DITOLAK', 'MENUNGGU', 'BATAL'], true)) {
            throw new RuntimeException('Status putusan tidak dikenal: '.$status);
        }
        $n = 0;
        $db = $this->db();
        $db->transaction(function () use ($ids, $status, $nota, $by, &$n, $db) {
            foreach ($ids as $id) {
                $db->update(
                    'UPDATE `dw_ajuan` SET `status`=?, `putus_at`=?, `putus_oleh`=?, `putus_nota`=?
                      WHERE `id`=?',
                    [$status, self::ms(), self::pot($by, 120), self::pot($nota, 255), self::s($id)]
                );
                $n++;
            }
        });

        return ['saved' => true, 'jumlah' => $n, 'status' => $status];
    }

    public function deleteAjuan(mixed $id): array
    {
        $this->db()->delete('DELETE FROM `dw_ajuan` WHERE `id` = ?', [self::s($id)]);

        return ['deleted' => true, 'id' => self::s($id)];
    }

    /** One assignment, RAW row (not shaped): the gates read DIVISI and STATUS before deciding who may touch it. */
    public function ajuanById(mixed $id): ?array
    {
        $r = $this->db()->selectOne('SELECT * FROM `dw_ajuan` WHERE `id` = ?', [self::s($id)]);

        return $r ? (array) $r : null;
    }

    /**
     * Filtered assignment listing for v1 (Antrean + Kalender screens).
     * Same visibility as getAll. Filters: dwId, divisi, status[] (uppercase),
     * dari/sampai (in range OR still MENUNGGU, like getAll).
     */
    public function listAssignments(array $f): array
    {
        $sql = 'SELECT * FROM `dw_ajuan` WHERE 1 = 1';
        $par = [];
        if (self::s($f['dwId'] ?? '') !== '') {
            $sql .= ' AND `dw_id` = ?';
            $par[] = self::s($f['dwId']);
        }
        if (self::s($f['divisi'] ?? '') !== '') {
            $sql .= ' AND `divisi` = ?';
            $par[] = self::s($f['divisi']);
        }
        $st = array_values(array_filter(
            array_map(fn ($s) => strtoupper(trim((string) $s)), (array) ($f['status'] ?? [])),
            fn ($s) => $s !== ''
        ));
        if (count($st) > 0) {
            $sql .= ' AND `status` IN ('.implode(',', array_fill(0, count($st), '?')).')';
            foreach ($st as $s) {
                $par[] = $s;
            }
        }
        $dari = self::tglValid($f['dari'] ?? '');
        $sampai = self::tglValid($f['sampai'] ?? '');
        if ($dari !== '' && $sampai !== '' && $sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        if ($dari !== '' && $sampai !== '') {
            $sql .= ' AND ((`tgl` BETWEEN ? AND ?) OR `status` = \'MENUNGGU\')';
            $par[] = $dari;
            $par[] = $sampai;
        } else {
            if ($dari !== '') {
                $sql .= ' AND `tgl` >= ?';
                $par[] = $dari;
            }
            if ($sampai !== '') {
                $sql .= ' AND `tgl` <= ?';
                $par[] = $sampai;
            }
        }
        $sql .= ' ORDER BY `tgl`, `jam_mulai`';

        return array_map(fn ($r) => $this->bentukAjuan((array) $r), $this->db()->select($sql, $par));
    }

    // ---------------------------------------------------------------- permintaan

    public function permintaanById(mixed $id): ?array
    {
        $r = $this->db()->selectOne('SELECT * FROM `dw_permintaan` WHERE `id` = ?', [self::s($id)]);

        return $r ? (array) $r : null;
    }

    /**
     * How many are STAFFED for this request. COUNTED, not stored — a number
     * stored twice will disagree one day, and the wrong one is always the one
     * rarely looked at. BATAL/DITOLAK don't count: the gap is open again.
     */
    public function permintaanTerpenuhi(mixed $id): int
    {
        $r = $this->db()->selectOne(
            'SELECT COUNT(*) c FROM `dw_ajuan`
              WHERE `permintaan_id` = ? AND (`status`=\'MENUNGGU\' OR `status`=\'DISETUJUI\')',
            [self::s($id)]
        );

        return $r ? (int) $r->c : 0;
    }

    /**
     * Way 2 — a head requests just the COUNT ("Saturday needs 3 people,
     * 16:00–23:00"); HRD picks who. Suggested names are filtered on the
     * SERVER: unknown or NONAKTIF workers are dropped, trimmed to `jumlah`
     * (more suggestions than needed would silently raise the budget), and
     * schedule conflicts are checked NOW — not when HRD assigns — against
     * both booked rows and other heads' pending suggestions. The request is
     * still saved; `bentrok` tells the screen who was dropped and why.
     * Editing returns the request to MENUNGGU (it must be re-seen by HRD)
     * but keeps the original requester in dibuat_*; the editor goes to
     * diubah_*.
     */
    public function savePermintaan(mixed $row, string $by): array
    {
        $row = is_array($row) ? $row : [];

        $tgl = self::tglValid($row['tgl'] ?? '');
        $m = self::jamValid($row['m'] ?? '');
        $sj = self::jamValid($row['s'] ?? '');
        $div = self::pot($row['divisi'] ?? '', 16);
        $jml = (int) ($row['jumlah'] ?? 0);
        if ($tgl === '') {
            throw new RuntimeException('Tanggal permintaan wajib diisi');
        }
        if ($m === '' || $sj === '') {
            throw new RuntimeException('Jam mulai dan jam selesai wajib diisi');
        }
        if ($div === '') {
            throw new RuntimeException('Divisi wajib diisi');
        }
        // An upper bound on purpose: a number that big is almost always a
        // typo, and HRD only notices after opening fifty empty assign rows.
        if ($jml < 1 || $jml > 30) {
            throw new RuntimeException('Jumlah orang harus antara 1 dan 30');
        }

        // The id is computed ABOVE the suggestion filter: bentrokUsulan()
        // needs to know which request is being edited or opening Edit + Save
        // would drop all its own suggestions as self-conflicts.
        $id = self::pot($row['id'] ?? '', 32);
        $baru = ($id === '');
        if ($baru) {
            $id = self::idBaru('PM');
        }

        $usulan = [];
        $ditolak = [];
        if (isset($row['usulan']) && is_array($row['usulan']) && count($row['usulan'])) {
            $minta = [];
            foreach ($row['usulan'] as $u) {
                $u = self::pot($u, 32);
                if ($u !== '' && ! in_array($u, $minta, true)) {
                    $minta[] = $u;
                }
            }
            if (count($minta)) {
                $cek = $this->db()->select(
                    'SELECT `id` FROM `dw_pekerja` WHERE `status`=\'AKTIF\' AND `id` IN ('.implode(',', array_fill(0, count($minta), '?')).')',
                    $minta
                );
                $sah = [];
                foreach ($cek as $r) {
                    $sah[] = (string) $r->id;
                }
                // The head's mention order is kept, not the database's — the
                // first named is usually the most wanted.
                foreach ($minta as $u) {
                    if (! in_array($u, $sah, true) || count($usulan) >= $jml) {
                        continue;
                    }
                    $b = $this->bentrokAjuanRow($u, $tgl, $m, $sj, $div);
                    if (! $b) {
                        $b = $this->bentrokUsulan($u, $tgl, $m, $sj, $div, ($baru ? '' : $id));
                    }
                    if ($b) {
                        $b['dwId'] = $u;
                        $b['nama'] = $this->namaPekerja($u);
                        $ditolak[] = $b;

                        continue;
                    }
                    $usulan[] = $u;
                }
            }
        }

        $now = self::ms();
        $by = self::pot($by, 120);
        if ($baru) {
            $this->db()->statement(
                'INSERT INTO `dw_permintaan`
                   (`id`,`divisi`,`tgl`,`jam_mulai`,`jam_selesai`,`posisi`,`jumlah`,`catatan`,
                    `usulan`,`status`,`dibuat_at`,`dibuat_oleh`)
                 VALUES (?,?,?,?,?,?,?,?,?,\'MENUNGGU\',?,?)',
                [$id, $div, $tgl, $m, $sj,
                    self::pot($row['posisi'] ?? '', 60),
                    $jml,
                    self::pot($row['catatan'] ?? '', 255),
                    self::pot(implode(',', $usulan), 400),
                    $now, $by]
            );
        } else {
            $this->db()->statement(
                'UPDATE `dw_permintaan` SET
                   `divisi`=?, `tgl`=?, `jam_mulai`=?, `jam_selesai`=?,
                   `posisi`=?, `jumlah`=?, `catatan`=?, `usulan`=?,
                   `status`=\'MENUNGGU\', `diubah_at`=?, `diubah_oleh`=?,
                   `putus_at`=0, `putus_oleh`=\'\', `putus_nota`=\'\'
                 WHERE `id`=?',
                [$div, $tgl, $m, $sj,
                    self::pot($row['posisi'] ?? '', 60),
                    $jml,
                    self::pot($row['catatan'] ?? '', 255),
                    self::pot(implode(',', $usulan), 400),
                    $now, $by, $id]
            );
        }
        $ada = $this->permintaanById($id);

        return ['saved' => true, 'row' => $ada ? $this->bentukPermintaan($ada) : null,
            'bentrok' => $ditolak];
    }

    /** One worker's name for conflict messages: ids alone can't be acted on. */
    public function namaPekerja(string $id): string
    {
        $r = $this->db()->selectOne('SELECT `nama` FROM `dw_pekerja` WHERE `id` = ?', [self::s($id)]);

        return $r ? (string) $r->nama : $id;
    }

    public function decidePermintaan(mixed $id, mixed $status, mixed $nota, string $by): array
    {
        $status = strtoupper(self::s($status));
        if (! in_array($status, ['DISETUJUI', 'DITOLAK', 'MENUNGGU', 'BATAL'], true)) {
            throw new RuntimeException('Status putusan tidak dikenal: '.$status);
        }
        $n = $this->db()->update(
            'UPDATE `dw_permintaan` SET `status`=?, `putus_at`=?, `putus_oleh`=?, `putus_nota`=?
              WHERE `id`=?',
            [$status, self::ms(), self::pot($by, 120), self::pot($nota, 255), self::s($id)]
        );
        if ($n === 0 && ! $this->permintaanById($id)) {
            throw new RuntimeException('Permintaan tidak ditemukan: '.self::s($id));
        }

        return ['saved' => true, 'id' => self::s($id), 'status' => $status];
    }

    /**
     * HRD points at the people for a request. Assigning APPROVES at once:
     * the decider is the assigner, so a second Approve click for a row just
     * created would be an empty step — skipped, then forgotten, and shifts
     * stuck in MENUNGGU never reach the Jadwal calendar at all.
     * Conflicts still hold (every assignment goes through the same
     * saveAjuan()); the held-back ones are REPORTED, not silently skipped —
     * an assignment quietly one person short is only noticed that night,
     * short-staffed. `timpa` stays OFF on purpose: moving someone booked by
     * another division is HRD's call, never code's.
     */
    public function assignDw(mixed $permintaanId, mixed $dwIds, string $by): array
    {
        $pm = $this->permintaanById($permintaanId);
        if (! $pm) {
            throw new RuntimeException('Permintaan tidak ditemukan: '.self::s($permintaanId));
        }
        if (! is_array($dwIds) || ! count($dwIds)) {
            throw new RuntimeException('Pilih dulu siapa yang ditugaskan');
        }

        $now = self::ms();
        $by = self::pot($by, 120);
        $masuk = [];
        $tertahan = [];

        foreach ($dwIds as $dwId) {
            $dwId = self::pot($dwId, 32);
            if ($dwId === '') {
                continue;
            }
            $hasil = $this->saveAjuan([
                'dwId' => $dwId, 'tgl' => $pm['tgl'],
                'm' => $pm['jam_mulai'], 's' => $pm['jam_selesai'],
                'divisi' => $pm['divisi'], 'posisi' => $pm['posisi'],
                'catatan' => $pm['catatan'],
            ], $by);
            if (empty($hasil['saved'])) {
                $tertahan[] = $hasil['bentrok'];

                continue;
            }

            $aj = isset($hasil['row']) ? $hasil['row'] : null;
            if (! $aj) {
                continue;
            }
            // Straight to DISETUJUI + tagged with this request. Done HERE,
            // not inside saveAjuan(): that guard ALWAYS forces MENUNGGU on
            // purpose and must not be loosened for any door.
            $this->db()->update(
                'UPDATE `dw_ajuan` SET `permintaan_id`=?, `status`=\'DISETUJUI\',
                   `putus_at`=?, `putus_oleh`=?, `putus_nota`=\'Ditugaskan dari permintaan head\'
                 WHERE `id`=?',
                [$pm['id'], $now, $by, $aj['id']]
            );
            $masuk[] = ['id' => $aj['id'], 'dwId' => $dwId];
        }

        // The request turns DISETUJUI once anyone is assigned — even
        // partially. "Partial" is deliberately NOT its own status: how many
        // are filled is counted from the rows and written plainly ("2 of 3").
        if (count($masuk)) {
            $this->db()->update(
                'UPDATE `dw_permintaan` SET `status`=\'DISETUJUI\', `putus_at`=?, `putus_oleh`=?
                  WHERE `id`=?',
                [$now, $by, $pm['id']]
            );
        }

        return ['saved' => true, 'masuk' => count($masuk), 'ditugaskan' => $masuk,
            'tertahan' => $tertahan, 'terpenuhi' => $this->permintaanTerpenuhi($pm['id']),
            'jumlah' => (int) $pm['jumlah']];
    }

    /**
     * Deletes the request. Issued assignments are NOT deleted with it — the
     * people were already promised to come, and deleting the request does not
     * un-promise that. Only the link is released.
     */
    public function deletePermintaan(mixed $id): array
    {
        $this->db()->update('UPDATE `dw_ajuan` SET `permintaan_id`=\'\' WHERE `permintaan_id` = ?', [self::s($id)]);
        $this->db()->delete('DELETE FROM `dw_permintaan` WHERE `id` = ?', [self::s($id)]);

        return ['deleted' => true, 'id' => self::s($id)];
    }

    /**
     * Filtered request listing for v1 (Permintaan + Antrean screens). Same
     * visibility as getAll. Filters: divisi, status[] (uppercase),
     * dari/sampai (in range OR still MENUNGGU, like getAll).
     */
    public function listRequests(array $f): array
    {
        $sql = 'SELECT * FROM `dw_permintaan` WHERE 1 = 1';
        $par = [];
        if (self::s($f['divisi'] ?? '') !== '') {
            $sql .= ' AND `divisi` = ?';
            $par[] = self::s($f['divisi']);
        }
        $st = array_values(array_filter(
            array_map(fn ($s) => strtoupper(trim((string) $s)), (array) ($f['status'] ?? [])),
            fn ($s) => $s !== ''
        ));
        if (count($st) > 0) {
            $sql .= ' AND `status` IN ('.implode(',', array_fill(0, count($st), '?')).')';
            foreach ($st as $s) {
                $par[] = $s;
            }
        }
        $dari = self::tglValid($f['dari'] ?? '');
        $sampai = self::tglValid($f['sampai'] ?? '');
        if ($dari !== '' && $sampai !== '' && $sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        if ($dari !== '' && $sampai !== '') {
            $sql .= ' AND ((`tgl` BETWEEN ? AND ?) OR `status` = \'MENUNGGU\')';
            $par[] = $dari;
            $par[] = $sampai;
        } else {
            if ($dari !== '') {
                $sql .= ' AND `tgl` >= ?';
                $par[] = $dari;
            }
            if ($sampai !== '') {
                $sql .= ' AND `tgl` <= ?';
                $par[] = $sampai;
            }
        }
        $sql .= ' ORDER BY `tgl`';

        return array_map(fn ($r) => $this->bentukPermintaan((array) $r), $this->db()->select($sql, $par));
    }

    // ---------------------------------------------------------------- hadir
    //
    // Attendance, replacement, payment marks, settings and the full wipe —
    // cluster (2), issue #14. Money follows attendance (16 Sep 2026): ALFA is
    // unpaid, '' / HADIR / TELAT are paid — computed by the frontend via
    // hadirDibayar(), never here.

    /**
     * Confirms who really came. Only on DISETUJUI rows: attendance on a
     * still-MENUNGGU row means nothing — the person is not scheduled — and
     * since pay follows attendance it would mislead. '' resets to
     * "not confirmed".
     */
    public function saveHadir(mixed $id, mixed $hadir, mixed $nota, string $by): array
    {
        $hadir = strtoupper(self::s($hadir));
        if (! in_array($hadir, ['', 'HADIR', 'TELAT', 'ALFA'], true)) {
            throw new RuntimeException('Kehadiran tidak dikenal: '.$hadir);
        }
        $ada = $this->ajuanById($id);
        if (! $ada) {
            throw new RuntimeException('Ajuan tidak ditemukan: '.self::s($id));
        }
        if ($ada['status'] !== 'DISETUJUI') {
            throw new RuntimeException('Kehadiran hanya bisa dicatat untuk shift yang sudah disetujui.');
        }
        $this->db()->update(
            'UPDATE `dw_ajuan` SET `hadir`=?, `hadir_nota`=?, `hadir_oleh`=?, `hadir_at`=?
              WHERE `id`=?',
            [$hadir, self::pot($nota, 255), self::pot($by, 120), self::ms(), self::s($id)]
        );

        return ['saved' => true, 'id' => self::s($id)];
    }

    /**
     * The approved worker calls in sick; someone else comes instead. The old
     * row is NOT re-pointed nor deleted — its no-show history is what HR
     * checks before calling the same person again — it is marked ALFA with
     * the reason, and the replacement is born as its OWN row, already
     * DISETUJUI and already HADIR, so pay moves to who really came with no
     * extra step. permintaan_id is COPIED along, or the head request that
     * birthed it reads "0 of 1" and HRD assigns a second person to a shift
     * that already has its replacement. ONE transaction: stopping halfway
     * leaves the shift with nobody, or two people for one slot.
     */
    public function gantiOrang(mixed $id, mixed $dwBaru, mixed $nota, string $by): array
    {
        $id = self::s($id);
        $dwBaru = self::pot($dwBaru, 32);
        if ($id === '' || $dwBaru === '') {
            throw new RuntimeException('Butuh shift dan penggantinya');
        }

        $a = $this->ajuanById($id);
        if (! $a) {
            throw new RuntimeException('Shift tidak ditemukan: '.$id);
        }
        if ($a['status'] !== 'DISETUJUI') {
            throw new RuntimeException('Hanya shift yang sudah disetujui yang bisa diganti orangnya.');
        }
        if ((string) $a['dw_id'] === (string) $dwBaru) {
            throw new RuntimeException('Penggantinya orang yang sama.');
        }

        $baru = $this->db()->selectOne('SELECT `nama`,`status` FROM `dw_pekerja` WHERE `id` = ?', [$dwBaru]);
        if (! $baru) {
            throw new RuntimeException('Pengganti tidak ditemukan: '.$dwBaru);
        }
        if ($baru->status === 'NONAKTIF' || $baru->status === 'BLOKIR') {
            throw new RuntimeException($baru->nama.' berstatus tidak aktif dan tidak bisa dijadwalkan.');
        }

        $B = $this->bentrokAjuanRow($dwBaru, $a['tgl'], $a['jam_mulai'], $a['jam_selesai'], $a['divisi']);
        if ($B) {
            throw new RuntimeException($baru->nama.' sudah punya shift yang jamnya bertindih di tanggal itu ('
                .$B['tgl'].' '.$B['m'].'-'.$B['s'].').');
        }

        $r2 = $this->db()->selectOne('SELECT `nama` FROM `dw_pekerja` WHERE `id` = ?', [$a['dw_id']]);
        $namaLama = $r2 ? $r2->nama : $a['dw_id'];

        $t = self::ms();
        $idBaru = self::idBaru('AJ');
        $pm = isset($a['permintaan_id']) ? $a['permintaan_id'] : '';
        $nota = self::s($nota);
        $ekor = ($nota !== '') ? ' - '.$nota : '';
        $by = self::pot($by, 120);

        // Positional placeholders: each ? binds once, so the repeated
        // timestamps and names need no :t1/:t2/:t3 dance (HY093).
        $this->db()->transaction(function () use ($id, $baru, $dwBaru, $a, $namaLama, $ekor, $t, $by, $idBaru, $pm) {
            $this->db()->update(
                'UPDATE `dw_ajuan`
                    SET `hadir`=\'ALFA\', `hadir_nota`=?, `hadir_oleh`=?, `hadir_at`=?
                  WHERE `id`=?',
                [self::pot('Digantikan '.$baru->nama.$ekor, 255), $by, $t, $id]
            );
            $this->db()->insert(
                'INSERT INTO `dw_ajuan`
                   (`id`,`dw_id`,`tgl`,`jam_mulai`,`jam_selesai`,`divisi`,`posisi`,`catatan`,
                    `status`,`dibuat_at`,`dibuat_oleh`,`putus_at`,`putus_oleh`,`putus_nota`,
                    `hadir`,`hadir_nota`,`hadir_oleh`,`hadir_at`,`permintaan_id`)
                 VALUES (?,?,?,?,?,?,?,?,\'DISETUJUI\',?,?,?,?,?,\'HADIR\',?,?,?,?)',
                [$idBaru, $dwBaru, $a['tgl'], $a['jam_mulai'], $a['jam_selesai'],
                    $a['divisi'], $a['posisi'],
                    self::pot('Pengganti '.$namaLama, 255),
                    $t, $by, $t, $by,
                    self::pot('Pengganti '.$namaLama.$ekor, 255),
                    self::pot('Hadir sebagai pengganti '.$namaLama, 255),
                    $by, $t,
                    self::pot($pm, 32)]
            );
        });

        $lama = $this->ajuanById($id);
        $bar = $this->ajuanById($idBaru);

        return [
            'saved' => true,
            'lama' => $lama ? $this->bentukAjuan($lama) : null,
            'baru' => $bar ? $this->bentukAjuan($bar) : null,
        ];
    }

    // ---------------------------------------------------------------- bayar

    /**
     * The "already transferred" tick on the Pembayaran screen. Its OWN path,
     * not simpanSetting: it touches ONE key read-modify-write inside ONE
     * transaction (SELECT … FOR UPDATE kept), so two people ticking at once
     * never overwrite each other's tariffs and quotas — and HRD may tick it,
     * while simpanSetting's hr/akses keys stay admin-only.
     */
    public function tandaiBayar(mixed $senin, mixed $kunciTujuan, mixed $nyala, string $by): array
    {
        $senin = self::tglValid($senin);
        $kunciTujuan = self::pot($kunciTujuan, 120);
        if ($senin === '' || $kunciTujuan === '') {
            throw new RuntimeException('Penanda pembayaran butuh minggu & tujuan');
        }
        $nyala = ! empty($nyala);
        $by = self::pot($by, 120);
        $k = $senin.'|'.$kunciTujuan;

        $this->db()->transaction(function () use ($k, $nyala, $by) {
            $row = $this->db()->selectOne('SELECT `data` FROM `dw_setting` WHERE `id` = 1 FOR UPDATE');
            $data = ($row && $row->data !== null && $row->data !== '') ? json_decode($row->data, true) : [];
            if (! is_array($data)) {
                $data = [];
            }
            if (! isset($data['bayarLunas']) || ! is_array($data['bayarLunas'])) {
                $data['bayarLunas'] = [];
            }
            if ($nyala) {
                $data['bayarLunas'][$k] = ['at' => self::ms(), 'oleh' => $by];
            } else {
                unset($data['bayarLunas'][$k]);
            }
            $this->db()->statement(
                'INSERT INTO `dw_setting` (`id`,`data`,`updated_at`,`updated_by`) VALUES (1,?,?,?)
                 ON DUPLICATE KEY UPDATE `data`=VALUES(`data`), `updated_at`=VALUES(`updated_at`), `updated_by`=VALUES(`updated_by`)',
                [json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::ms(), $by]
            );
        });

        return ['saved' => true, 'kunci' => $k, 'nyala' => $nyala ? 1 : 0];
    }

    // ---------------------------------------------------------------- setting

    /**
     * Writes the WHOLE setting blob (tariffs, quotas, default hours,
     * position maps, hr list, access matrix, payment ticks). HRD sets tariffs
     * and quotas — that is their job. What they may NOT touch is the hr list
     * itself (an HRD who can edit the HRD list is no restriction at all) nor
     * the akses matrix (the Hak Akses screen's, admin-only): both are KEPT
     * from the stored value when the caller is not a module admin — kept
     * AS-IS, so a never-set key stays unset instead of filling from an
     * unauthorised sender. The one exception to whole-blob writes is the
     * payment tick above, which has its own path.
     */
    public function saveSetting(mixed $data, string $by, bool $bolehUbahHr = true): array
    {
        if (! is_array($data) && ! is_object($data)) {
            throw new RuntimeException('Payload setting kosong/invalid');
        }
        if (! $bolehUbahHr) {
            $lama = json_decode(json_encode($this->setting()), true);
            $hrLama = (is_array($lama) && isset($lama['hr']) && is_array($lama['hr'])) ? $lama['hr'] : [];
            $data = (array) $data;
            $data['hr'] = $hrLama;
            $data['akses'] = (is_array($lama) && isset($lama['akses']) && is_array($lama['akses']))
                ? $lama['akses'] : [];
        }
        $this->db()->statement(
            'INSERT INTO `dw_setting` (`id`,`data`,`updated_at`,`updated_by`) VALUES (1,?,?,?)
             ON DUPLICATE KEY UPDATE `data`=VALUES(`data`), `updated_at`=VALUES(`updated_at`), `updated_by`=VALUES(`updated_by`)',
            [json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::ms(), self::pot($by, 120)]
        );

        return ['saved' => true, 'ts' => gmdate('c')];
    }

    /**
     * Empties every head request and every assignment, so the module can
     * start from zero. The talent pool (names, phone numbers, months of
     * no-show track record) only follows on explicit request. The setting
     * blob stays. ONE transaction; no undo.
     */
    public function kosongkanSemua(bool $ikutPekerja, string $by): array
    {
        $nAjuan = 0;
        $nMinta = 0;
        $nOrang = 0;
        $this->db()->transaction(function () use ($ikutPekerja, &$nAjuan, &$nMinta, &$nOrang) {
            $nAjuan = $this->db()->delete('DELETE FROM `dw_ajuan`');
            $nMinta = $this->db()->delete('DELETE FROM `dw_permintaan`');
            $nOrang = $ikutPekerja ? $this->db()->delete('DELETE FROM `dw_pekerja`') : 0;
        });

        return ['cleared' => true, 'ajuan' => $nAjuan, 'permintaan' => $nMinta,
            'pekerja' => $nOrang, 'ikutPekerja' => $ikutPekerja,
            'oleh' => self::pot($by, 120), 'ts' => gmdate('c')];
    }

    // ---------------------------------------------------------------- diagnostics

    public function ping(): array
    {
        return ['pong' => true, 'backend' => 'laravel', 'env' => Modules::envLabel(), 'db' => Modules::databaseName('dw'), 'ts' => gmdate('c')];
    }

    public function stats(): array
    {
        $out = ['backend' => 'laravel', 'env' => Modules::envLabel(), 'db' => Modules::databaseName('dw'),
            'pekerja' => 0, 'ajuan' => 0, 'menunggu' => 0, 'disetujui' => 0, 'ada' => false];
        try {
            $out['pekerja'] = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `dw_pekerja`')->c;
            $out['ajuan'] = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `dw_ajuan`')->c;
            $out['menunggu'] = (int) $this->db()->selectOne("SELECT COUNT(*) c FROM `dw_ajuan` WHERE `status`='MENUNGGU'")->c;
            $out['disetujui'] = (int) $this->db()->selectOne("SELECT COUNT(*) c FROM `dw_ajuan` WHERE `status`='DISETUJUI'")->c;
            $out['ada'] = true;
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        $out['ts'] = gmdate('c');

        return $out;
    }
}
