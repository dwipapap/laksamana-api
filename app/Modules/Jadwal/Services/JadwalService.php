<?php

namespace App\Modules\Jadwal\Services;

use App\Support\JsonDoc;
use App\Support\Legacy\Sesi;
use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use stdClass;

/**
 * Jadwal Shift — port of jadwal-mysql/lib_jadwal_mysql.php.
 *
 * Writes are GRANULAR (one row per crew × date), never a whole-state blob:
 * every division head edits their own sheet at the same time, and a blob
 * would let the last saver erase the others.
 *
 * Permission rule in one sentence: a division's schedule is written by that
 * division's head; module admins (= HRD) may do everything. Checked PER ROW.
 *
 * Tables: jadwal_sel (PK user_id,tgl), jadwal_pengajuan, jadwal_setting (id=1 blob).
 */
class JadwalService
{
    /** Synonyms — twin of DIV_SINONIM in deploy/jadwal/index.html (whole-word match). */
    public const DIV_SINONIM = [
        'bar' => ['bar', 'bartender'],
        'kitchen' => ['kitchen', 'dapur'],
        'floor' => ['floor', 'service', 'waiter', 'waitress', 'host', 'hostess'],
        'cashier' => ['cashier', 'kasir'],
    ];

    /** Office wins over any division word ("Kasir Office" is office staff). */
    public const KANTOR_KATA = ['office', 'kantor'];

    public const DIV_NONSHIFT = 'nonshift';

    public const STATUS_BERJALAN = ['MENUNGGU', 'MENUNGGU_HRD'];

    /** Setting keys that are MAPS: an empty one must be written as {} not []. */
    private const PETA = ['shifts', 'heads', 'divOverride', 'jabatan', 'shiftKru', 'template'];

    private ?array $settingArr = null;

    public function __construct(private readonly Sesi $sesi) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('jadwal');
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    private static function ms(): int
    {
        return (int) (microtime(true) * 1000);
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

    // ------------------------------------------------------------ setting

    /** The blob exactly as stored (objects stay objects) — sent to the frontend. */
    public function setting(): mixed
    {
        try {
            $row = $this->db()->selectOne('SELECT `data` FROM `jadwal_setting` WHERE `id` = 1');
        } catch (\Throwable) {
            return new stdClass;
        }
        if (! $row || $row->data === null || $row->data === '') {
            return new stdClass;
        }
        $v = json_decode($row->data);

        return $v === null ? new stdClass : $v;
    }

    /** Array view for rule checks (never written back). */
    private function settingArr(): array
    {
        return $this->settingArr ??= JsonDoc::toArray($this->setting());
    }

    private function heads(): array
    {
        $h = $this->settingArr()['heads'] ?? [];

        return is_array($h) ? $h : [];
    }

    public function divisiUser(string $uid): string
    {
        $ov = $this->settingArr()['divOverride'] ?? [];
        if (is_array($ov) && isset($ov[$uid]) && $ov[$uid] !== '') {
            return (string) $ov[$uid];
        }
        $roster = $this->sesi->roster();
        if (! isset($roster[$uid])) {
            return self::DIV_NONSHIFT;
        }
        $kata = preg_split('/[^a-z]+/', strtolower((string) ($roster[$uid]['keterangan'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (self::KANTOR_KATA as $x) {
            if (in_array($x, $kata, true)) {
                return self::DIV_NONSHIFT;
            }
        }
        foreach (self::DIV_SINONIM as $kode => $sin) {
            foreach ($sin as $x) {
                if (in_array($x, $kata, true)) {
                    return $kode;
                }
            }
        }

        return self::DIV_NONSHIFT;
    }

    /** Office roster with each User's Divisi attached (read model for new apps). */
    public function rosterWithDivisi(): array
    {
        $out = [];
        foreach ($this->sesi->roster() as $uid => $m) {
            $m = (array) $m;
            $m['divisi'] = $this->divisiUser((string) $uid);
            $out[] = $m;
        }

        return $out;
    }

    public function isHead(?array $u, string $div): bool
    {
        if (! $u) {
            return false;
        }
        $daftar = $this->heads()[$div] ?? [];
        foreach (is_array($daftar) ? $daftar : [] as $id) {
            if ((string) $id === (string) $u['id']) {
                return true;
            }
        }

        return false;
    }

    public function divHasHead(string $div): bool
    {
        $d = $this->heads()[$div] ?? null;

        return is_array($d) && count($d) > 0;
    }

    public function anyHead(): bool
    {
        foreach ($this->heads() as $d) {
            if (is_array($d) && count($d)) {
                return true;
            }
        }

        return false;
    }

    private function headAnywhere(?array $u): bool
    {
        if (! $u) {
            return false;
        }
        foreach ($this->heads() as $daftar) {
            foreach (is_array($daftar) ? $daftar : [] as $id) {
                if ((string) $id === (string) $u['id']) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function isAdmin(?array $u): bool
    {
        return Sesi::isModuleAdmin($u, 'jadwal');
    }

    /**
     * May $u write rows of crew member $uid? Throws `tidak_berhak:` otherwise.
     * No head configured yet => everyone with the module may (same as the screen).
     */
    public function assertMayWriteRow(?array $u, string $uid): void
    {
        if (self::isAdmin($u) || ! $this->anyHead()) {
            return;
        }
        // Office roster unreachable: fall back to "head of some division".
        if (! count($this->sesi->roster())) {
            if ($this->headAnywhere($u)) {
                return;
            }
            Sesi::rejectForbidden('Hanya head divisi yang bisa menyusun jadwal.');
        }
        $div = $this->divisiUser($uid);
        if ($div === self::DIV_NONSHIFT) {
            Sesi::rejectForbidden('Kru ini belum ditempatkan di divisi mana pun, jadi hanya admin modul yang bisa mengatur jadwalnya. Tempatkan dulu lewat Pengaturan → Penempatan Divisi.');
        }
        if (! $this->isHead($u, $div)) {
            Sesi::rejectForbidden('Jadwal divisi '.$div.' hanya bisa disusun head divisi itu.');
        }
    }

    // ------------------------------------------------------------ reads

    /** Narrow read for absensi: shifts of one/all crew in a date range, default times filled in. */
    public function shiftRange(string $user, string $dari, string $sampai): array
    {
        $a = self::tglValid($dari);
        $b = self::tglValid($sampai);
        if ($a === '' || $b === '') {
            throw new RuntimeException('shiftHari butuh dari & sampai (YYYY-MM-DD)');
        }
        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }
        $sql = 'SELECT `user_id`,`tgl`,`shift`,`jam_mulai`,`jam_selesai` FROM `jadwal_sel` WHERE `tgl` BETWEEN ? AND ?';
        $par = [$a, $b];
        if ($user !== '') {
            $sql .= ' AND `user_id` = ?';
            $par[] = $user;
        }
        // Key is `shifts` (not `shift`) — `shift` only as fallback for very old data.
        $set = $this->settingArr();
        $def = is_array($set['shifts'] ?? null) ? $set['shifts'] : (is_array($set['shift'] ?? null) ? $set['shift'] : []);

        $rows = [];
        foreach ($this->db()->select($sql, $par) as $r) {
            $kode = (string) $r->shift;
            $d = (isset($def[$kode]) && is_array($def[$kode])) ? $def[$kode] : [];
            $m = (string) $r->jam_mulai;
            if ($m === '' && isset($d['m'])) {
                $m = (string) $d['m'];
            }
            $s = (string) $r->jam_selesai;
            if ($s === '' && isset($d['s'])) {
                $s = (string) $d['s'];
            }
            $rows[] = ['u' => $r->user_id, 'd' => $r->tgl, 't' => $kode, 'm' => $m, 's' => $s,
                'libur' => ! empty($d['libur']) ? 1 : 0];
        }

        return ['dari' => $a, 'sampai' => $b, 'rows' => $rows];
    }

    public function readAll(string $dari, string $sampai): array
    {
        $d = self::tglValid($dari);
        $sm = self::tglValid($sampai);
        $cols = '`user_id`,`tgl`,`shift`,`jam_mulai`,`jam_selesai`,`catatan`';
        $rows = ($d !== '' && $sm !== '')
            ? $this->db()->select("SELECT $cols FROM `jadwal_sel` WHERE `tgl` BETWEEN ? AND ?", [$d, $sm])
            : $this->db()->select("SELECT $cols FROM `jadwal_sel`");
        $sel = [];
        foreach ($rows as $r) {
            $sel[] = ['u' => $r->user_id, 'd' => $r->tgl, 't' => $r->shift, 'm' => $r->jam_mulai, 's' => $r->jam_selesai, 'n' => $r->catatan];
        }

        $aju = array_map(fn ($r) => $this->pengajuanJson((array) $r), $this->db()->select(
            "SELECT * FROM (
               SELECT * FROM `jadwal_pengajuan` WHERE `status` IN ('MENUNGGU','MENUNGGU_HRD')
               UNION ALL
               SELECT * FROM (SELECT * FROM `jadwal_pengajuan` WHERE `status` NOT IN ('MENUNGGU','MENUNGGU_HRD')
                              ORDER BY `putus_at` DESC LIMIT 200) x
             ) y ORDER BY `dibuat_at` DESC"
        ));

        return ['setting' => $this->setting(), 'sel' => $sel, 'pengajuan' => $aju];
    }

    /**
     * Filtered request listing for v1 (Pengajuan + Jadwal Saya screens).
     * Same visibility as legacy getAll — any module holder sees all rows,
     * filters only narrow. Full table, newest first (no 200-row cap).
     * Supported keys: userId, status[] (uppercase), dari / sampai (YYYY-MM-DD,
     * overlap: the request spans at least one day inside the range).
     */
    public function listRequests(array $f): array
    {
        $sql = 'SELECT * FROM `jadwal_pengajuan` WHERE 1 = 1';
        $par = [];
        if (($f['userId'] ?? '') !== '') {
            $sql .= ' AND `user_id` = ?';
            $par[] = $f['userId'];
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
        if ($dari !== '') {
            $sql .= ' AND `tgl_selesai` >= ?';
            $par[] = $dari;
        }
        if ($sampai !== '') {
            $sql .= ' AND `tgl_mulai` <= ?';
            $par[] = $sampai;
        }
        $sql .= ' ORDER BY `dibuat_at` DESC';

        return array_map(fn ($r) => $this->pengajuanJson((array) $r), $this->db()->select($sql, $par));
    }

    public function pengajuanJson(array $r): array
    {
        return [
            'id' => $r['id'], 'userId' => $r['user_id'], 'jenis' => $r['jenis'],
            'dari' => $r['tgl_mulai'], 'sampai' => $r['tgl_selesai'],
            'alasan' => (string) $r['alasan'], 'status' => $r['status'],
            'headAt' => isset($r['head_at']) ? (int) $r['head_at'] : 0,
            'headOleh' => isset($r['head_oleh']) ? (string) $r['head_oleh'] : '',
            'shift' => isset($r['shift']) ? (string) $r['shift'] : '',
            'jamMulai' => isset($r['jam_mulai']) ? (string) $r['jam_mulai'] : '',
            'jamSelesai' => isset($r['jam_selesai']) ? (string) $r['jam_selesai'] : '',
            'dibuatAt' => (int) $r['dibuat_at'], 'dibuatOleh' => $r['dibuat_oleh'],
            'putusAt' => (int) $r['putus_at'], 'putusOleh' => $r['putus_oleh'],
            'putusNota' => $r['putus_nota'],
        ];
    }

    public function pengajuanById(string $id): ?array
    {
        $r = $this->db()->selectOne('SELECT * FROM `jadwal_pengajuan` WHERE `id` = ?', [self::s($id)]);

        return $r ? (array) $r : null;
    }

    // ------------------------------------------------------------ writes

    /**
     * Upsert + delete cells in ONE transaction. Every crew member touched is
     * permission-checked BEFORE the transaction opens. Malformed rows are skipped.
     */
    public function saveCells(mixed $rows, mixed $hapus, string $by, ?array $u): array
    {
        $rows = is_array($rows) ? $rows : [];
        $hapus = is_array($hapus) ? $hapus : [];

        $semua = [];
        foreach (array_merge($rows, $hapus) as $r) {
            $r = (array) $r;
            if (isset($r['u'])) {
                $semua[(string) $r['u']] = 1;
            }
        }
        foreach (array_keys($semua) as $uid) {
            $this->assertMayWriteRow($u, (string) $uid);
        }

        $now = self::ms();
        $by = mb_substr(self::s($by), 0, 120);
        $nIsi = 0;
        $nHapus = 0;
        $this->db()->transaction(function () use ($rows, $hapus, $now, $by, &$nIsi, &$nHapus) {
            foreach ($rows as $r) {
                $r = (array) $r;
                $uid = mb_substr(self::s($r['u'] ?? ''), 0, 64);
                $d = self::tglValid($r['d'] ?? '');
                $t = mb_substr(self::s($r['t'] ?? ''), 0, 16);
                if ($uid === '' || $d === '' || $t === '') {
                    continue;
                }
                $this->db()->statement(
                    'INSERT INTO `jadwal_sel` (`user_id`,`tgl`,`shift`,`jam_mulai`,`jam_selesai`,`catatan`,`updated_at`,`updated_by`)
                     VALUES (?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE `shift`=VALUES(`shift`), `jam_mulai`=VALUES(`jam_mulai`),
                       `jam_selesai`=VALUES(`jam_selesai`), `catatan`=VALUES(`catatan`),
                       `updated_at`=VALUES(`updated_at`), `updated_by`=VALUES(`updated_by`)',
                    [$uid, $d, $t, self::jamValid($r['m'] ?? ''), self::jamValid($r['s'] ?? ''),
                        mb_substr(self::s($r['n'] ?? ''), 0, 120), $now, $by]
                );
                $nIsi++;
            }
            foreach ($hapus as $r) {
                $r = (array) $r;
                $uid = mb_substr(self::s($r['u'] ?? ''), 0, 64);
                $d = self::tglValid($r['d'] ?? '');
                if ($uid === '' || $d === '') {
                    continue;
                }
                $this->db()->delete('DELETE FROM `jadwal_sel` WHERE `user_id` = ? AND `tgl` = ?', [$uid, $d]);
                $nHapus++;
            }
        });

        return ['saved' => true, 'isi' => $nIsi, 'hapus' => $nHapus, 'ts' => gmdate('c')];
    }

    /** CI write probe: open + commit a transaction without writing anything. */
    public function probe(): array
    {
        return $this->saveCells([], [], 'ci-probe', null);
    }

    public function saveSetting(mixed $data, string $by): array
    {
        if (! is_array($data) && ! is_object($data)) {
            throw new RuntimeException('Payload setting kosong/invalid');
        }
        $d = (array) $data;
        foreach (self::PETA as $k) {
            if (isset($d[$k]) && is_array($d[$k]) && count($d[$k]) === 0) {
                $d[$k] = new stdClass;
            }
        }
        $this->db()->statement(
            'INSERT INTO `jadwal_setting` (`id`,`data`,`updated_at`,`updated_by`) VALUES (1,?,?,?)
             ON DUPLICATE KEY UPDATE `data`=VALUES(`data`), `updated_at`=VALUES(`updated_at`), `updated_by`=VALUES(`updated_by`)',
            [json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::ms(), mb_substr(self::s($by), 0, 120)]
        );
        $this->settingArr = null;
        app(HeadDirectory::class)->flush();

        return ['saved' => true, 'ts' => gmdate('c')];
    }

    /** Status is ALWAYS forced to MENUNGGU here — deciding never goes through this path. */
    public function saveRequest(array $row, string $by): array
    {
        $id = mb_substr(self::s($row['id'] ?? ''), 0, 32);
        if ($id === '') {
            $id = 'A'.base_convert((string) self::ms(), 10, 36).random_int(100, 999);
        }
        $uid = mb_substr(self::s($row['userId'] ?? ''), 0, 64);
        $a = self::tglValid($row['dari'] ?? '');
        $b = self::tglValid($row['sampai'] ?? '');
        if ($b === '') {
            $b = $a;
        }
        $jenis = strtoupper(mb_substr(self::s($row['jenis'] ?? 'OFF'), 0, 16));
        if ($uid === '' || $a === '') {
            throw new RuntimeException('Pengajuan butuh kru dan tanggal');
        }
        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }
        $this->db()->statement(
            "INSERT INTO `jadwal_pengajuan`
               (`id`,`user_id`,`jenis`,`tgl_mulai`,`tgl_selesai`,`alasan`,`status`,`dibuat_at`,`dibuat_oleh`,`shift`,`jam_mulai`,`jam_selesai`)
             VALUES (?,?,?,?,?,?,'MENUNGGU',?,?,?,?,?)
             ON DUPLICATE KEY UPDATE `jenis`=VALUES(`jenis`), `tgl_mulai`=VALUES(`tgl_mulai`),
               `tgl_selesai`=VALUES(`tgl_selesai`), `alasan`=VALUES(`alasan`),
               `shift`=VALUES(`shift`), `jam_mulai`=VALUES(`jam_mulai`), `jam_selesai`=VALUES(`jam_selesai`)",
            [$id, $uid, $jenis, $a, $b, mb_substr(self::s($row['alasan'] ?? ''), 0, 2000),
                self::ms(), mb_substr(self::s($by), 0, 120),
                mb_substr(self::s($row['shift'] ?? ''), 0, 16),
                self::jamValid($row['jamMulai'] ?? ''), self::jamValid($row['jamSelesai'] ?? '')]
        );

        return ['saved' => true, 'id' => $id];
    }

    /**
     * Two-step approval, enforced here (not only on screen):
     *   MENUNGGU --head--> MENUNGGU_HRD --HRD(admin)--> DISETUJUI ; either may DITOLAK.
     * $adminHRD / $isHead null = caller without role info (gate skipped, legacy).
     */
    public function decide(string $id, mixed $status, mixed $nota, string $by, ?bool $adminHRD = null, ?bool $isHead = null): array
    {
        $id = self::s($id);
        $status = strtoupper(self::s($status));
        if (! in_array($status, ['DISETUJUI', 'MENUNGGU_HRD', 'DITOLAK', 'MENUNGGU'], true)) {
            throw new RuntimeException('Status putusan tidak dikenal: '.$status);
        }
        $lama = $this->pengajuanById($id);
        if (! $lama) {
            throw new RuntimeException('Pengajuan tidak ditemukan: '.$id);
        }
        $sebelum = strtoupper((string) $lama['status']);

        if ($adminHRD !== null) {
            if ($status === 'DISETUJUI') {
                if (! $adminHRD) {
                    throw new RuntimeException('Persetujuan akhir hanya oleh HRD. Yang bisa dilakukan head: meneruskan pengajuan ini ke HRD.');
                }
                if ($sebelum !== 'MENUNGGU_HRD') {
                    throw new RuntimeException('Pengajuan ini belum disetujui head divisinya, jadi belum bisa disahkan HRD.');
                }
            }
            if ($status === 'MENUNGGU_HRD') {
                if ($sebelum !== 'MENUNGGU') {
                    throw new RuntimeException('Hanya pengajuan yang masih menunggu head yang bisa diteruskan ke HRD.');
                }
                if ($isHead === false) {
                    throw new RuntimeException('Langkah pertama milik head divisi kru itu. HRD baru bisa mengesahkan sesudah head meneruskannya.');
                }
            }
        }

        $sql = $status === 'MENUNGGU_HRD'
            ? 'UPDATE `jadwal_pengajuan` SET `status`=?, `head_at`=?, `head_oleh`=?, `putus_nota`=? WHERE `id`=?'
            : 'UPDATE `jadwal_pengajuan` SET `status`=?, `putus_at`=?, `putus_oleh`=?, `putus_nota`=? WHERE `id`=?';
        $this->db()->update($sql, [$status, self::ms(), mb_substr(self::s($by), 0, 120), mb_substr(self::s($nota), 0, 255), $id]);

        return ['saved' => true, 'id' => $id, 'status' => $status];
    }

    public function deleteRequest(string $id): array
    {
        $this->db()->delete('DELETE FROM `jadwal_pengajuan` WHERE `id` = ?', [self::s($id)]);

        return ['deleted' => true, 'id' => self::s($id)];
    }

    /** Wipes ALL cells and requests (settings kept). Admin + confirmation word, checked by the caller. */
    public function clearAll(string $by): array
    {
        $nSel = 0;
        $nAju = 0;
        $this->db()->transaction(function () use (&$nSel, &$nAju) {
            $nSel = $this->db()->delete('DELETE FROM `jadwal_sel`');
            $nAju = $this->db()->delete('DELETE FROM `jadwal_pengajuan`');
        });

        return ['cleared' => true, 'sel' => $nSel, 'pengajuan' => $nAju, 'oleh' => mb_substr(self::s($by), 0, 120), 'ts' => gmdate('c')];
    }

    // ------------------------------------------------------------ diagnostics

    public function ping(): array
    {
        return ['pong' => true, 'backend' => 'laravel', 'env' => Modules::envLabel(), 'db' => Modules::databaseName('jadwal'), 'ts' => gmdate('c')];
    }

    public function stats(): array
    {
        $out = ['backend' => 'laravel', 'env' => Modules::envLabel(), 'db' => Modules::databaseName('jadwal'),
            'sel' => 0, 'pengajuan' => 0, 'menunggu' => 0, 'ada' => false];
        try {
            $out['sel'] = (int) $this->db()->selectOne('SELECT COUNT(*) n FROM `jadwal_sel`')->n;
            $out['pengajuan'] = (int) $this->db()->selectOne('SELECT COUNT(*) n FROM `jadwal_pengajuan`')->n;
            $out['menunggu'] = (int) $this->db()->selectOne("SELECT COUNT(*) n FROM `jadwal_pengajuan` WHERE `status`='MENUNGGU'")->n;
            $out['ada'] = true;
        } catch (\Throwable) {
        }
        $out['ts'] = gmdate('c');

        return $out;
    }
}
