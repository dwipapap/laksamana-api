<?php

namespace App\Modules\Jadwal\Services;

use App\Auth\AccountRepository;
use App\Auth\CoreAccountRepository;
use App\Support\Divisi;
use App\Support\JsonDoc;
use App\Support\Legacy\Sesi;
use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
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
 * Legacy tables: jadwal_sel (PK user_id,tgl), jadwal_pengajuan,
 * jadwal_setting (id=1 blob).
 *
 * On core (#47, docs/db/jadwal.md) the same shapes are rebuilt from the
 * jadwal tables: user ids on the wire stay legacy ids (joins translate
 * ULID ↔ legacy inside), and the setting blob is assembled from
 * jadwal_shift / jabatan / shiftKru / manajemen / pengaturan (+ heads and
 * divOverride from identity, as since #44).
 */
class JadwalService
{
    public const STATUS_BERJALAN = ['MENUNGGU', 'MENUNGGU_HRD'];

    /** Setting keys that are MAPS: an empty one must be written as {} not []. */
    private const PETA = ['shifts', 'heads', 'divOverride', 'jabatan', 'shiftKru', 'template'];

    private ?array $settingArr = null;

    private ?array $divisiInputs = null;

    /** Memoised core user maps: [legacy => ULID, ULID => legacy]. */
    private ?array $userMaps = null;

    public function __construct(
        private readonly Sesi $sesi,
        private readonly Divisi $divisi,
    ) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('jadwal');
    }

    /** Jadwal cut over (#47): the jadwal Modul reads and writes core tables. */
    public static function onCore(): bool
    {
        return Modules::connectionName('jadwal') === 'core';
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    private static function ms(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
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
        if (self::onCore()) {
            return $this->withDivisiMaps($this->coreSetting());
        }
        try {
            $row = $this->db()->selectOne('SELECT `data` FROM `jadwal_setting` WHERE `id` = 1');
        } catch (\Throwable) {
            return new stdClass;
        }
        if (! $row || $row->data === null || $row->data === '') {
            return new stdClass;
        }
        $v = json_decode($row->data);

        return $this->withDivisiMaps($v === null ? new stdClass : $v);
    }

    /**
     * Identity on core (#44): Kepala Divisi and Penempatan Divisi are no longer
     * in the blob; the wire shape rebuilds them as `heads` and `divOverride`.
     */
    private function withDivisiMaps(mixed $v): mixed
    {
        if (! AccountRepository::onCore() || ! $v instanceof stdClass) {
            return $v;
        }
        $core = app(CoreAccountRepository::class);
        $v->heads = (object) $core->headsByDivisi();
        $v->divOverride = (object) $core->penempatanMap();

        return $v;
    }

    /**
     * Assemble the setting blob from the normalised jadwal tables (#47).
     * Empty maps rebuild as {} (never []); an empty manajemen rebuilds as [].
     */
    private function coreSetting(): stdClass
    {
        $db = $this->db();
        $o = new stdClass;

        $shifts = new stdClass;
        try {
            foreach ($db->select('SELECT * FROM `jadwal_shift` ORDER BY `urutan`, `kode`') as $r) {
                // Absent keys stay absent: partial definitions round-trip verbatim.
                $def = [];
                if ($r->nama !== null) {
                    $def['n'] = (string) $r->nama;
                }
                if ($r->jam_mulai !== null) {
                    $def['m'] = (string) $r->jam_mulai;
                }
                if ($r->jam_selesai !== null) {
                    $def['s'] = (string) $r->jam_selesai;
                }
                if ($r->warna !== null) {
                    $def['w'] = (string) $r->warna;
                }
                if ($r->libur !== null) {
                    $def['libur'] = (int) $r->libur;
                }
                if ($r->urutan !== null) {
                    $def['urut'] = (int) $r->urutan;
                }
                if ($r->ekstra !== null && $r->ekstra !== '') {
                    foreach ((array) json_decode((string) $r->ekstra, true) as $k => $v) {
                        $def[$k] = $v;
                    }
                }
                $shifts->{$r->kode} = $def;
            }
        } catch (\Throwable) {
        }
        $o->shifts = $shifts;

        $jab = new stdClass;
        try {
            foreach ($db->select('SELECT u.legacy_id, j.jabatan FROM `jadwal_jabatan` j JOIN `user` u ON u.id = j.user_id') as $r) {
                $jab->{$r->legacy_id} = (string) $r->jabatan;
            }
        } catch (\Throwable) {
        }
        $o->jabatan = $jab;

        $kru = new stdClass;
        try {
            foreach ($db->select('SELECT u.legacy_id, k.kode_shift FROM `jadwal_shift_kru` k JOIN `user` u ON u.id = k.user_id') as $r) {
                $kru->{$r->legacy_id} = (string) $r->kode_shift;
            }
        } catch (\Throwable) {
        }
        $o->shiftKru = $kru;

        $man = [];
        try {
            foreach ($db->select('SELECT u.legacy_id FROM `jadwal_manajemen` m JOIN `user` u ON u.id = m.user_id ORDER BY m.urutan, m.id') as $r) {
                $man[] = (string) $r->legacy_id;
            }
        } catch (\Throwable) {
        }
        $o->manajemen = $man;

        try {
            $p = $db->selectOne('SELECT * FROM `jadwal_pengaturan` WHERE `legacy_id` = ? LIMIT 1', ['1']);
        } catch (\Throwable) {
            $p = null;
        }
        if ($p) {
            if ($p->maks_beruntun !== null) {
                $o->maksBeruntun = (int) $p->maks_beruntun;
            }
            if ($p->jeda_menit !== null) {
                $o->jedaMin = (int) $p->jeda_menit;
            }
            if ($p->ekstra !== null && $p->ekstra !== '') {
                foreach ((array) json_decode((string) $p->ekstra) as $k => $v) {
                    $o->{$k} = $v;
                }
            }
        }

        return $o;
    }

    /** Divisi resolution inputs: [divOverride, synonyms, office words] (core tables once identity is on core). */
    private function divisiInputs(): array
    {
        if (! AccountRepository::onCore()) {
            $ov = $this->settingArr()['divOverride'] ?? [];

            return [is_array($ov) ? $ov : [], Divisi::SYNONYMS, Divisi::OFFICE_WORDS];
        }
        $core = app(CoreAccountRepository::class);

        return [$core->penempatanMap(), ...$core->divisiWords()];
    }

    /** Array view for rule checks (never written back). */
    private function settingArr(): array
    {
        return $this->settingArr ??= JsonDoc::toArray($this->setting());
    }

    /** [legacy_id => ULID, ULID => legacy_id] from the core user table. */
    private function users(): array
    {
        if ($this->userMaps !== null) {
            return $this->userMaps;
        }
        $toUlid = [];
        $toLegacy = [];
        try {
            foreach ($this->db()->select('SELECT `id`, `legacy_id` FROM `user`') as $r) {
                $toUlid[(string) $r->legacy_id] = (string) $r->id;
                $toLegacy[(string) $r->id] = (string) $r->legacy_id;
            }
        } catch (\Throwable) {
        }

        return $this->userMaps = [$toUlid, $toLegacy];
    }

    /** Actor ULID for the technical columns, resolved by session name. */
    private function actorUlid(string $by): ?string
    {
        $by = self::s($by);
        if ($by === '') {
            return null;
        }
        try {
            $r = $this->db()->selectOne('SELECT `id` FROM `user` WHERE `nama` = ? ORDER BY `legacy_id` LIMIT 1', [$by]);
        } catch (\Throwable) {
            return null;
        }

        return $r ? (string) $r->id : null;
    }

    /** A crew member's Divisi, resolved by the shared Divisi service. */
    public function divisiUser(string $uid): string
    {
        [$ov, $syn, $office] = $this->divisiInputs ??= $this->divisiInputs();
        $roster = $this->sesi->roster();

        return Divisi::resolve($uid, $ov, isset($roster[$uid]) ? (array) $roster[$uid] : null, $syn, $office);
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
        return $this->divisi->isHeadOf($u, $div);
    }

    public function divHasHead(string $div): bool
    {
        return $this->divisi->divHasHead($div);
    }

    public function anyHead(): bool
    {
        return $this->divisi->anyHead();
    }

    private function headAnywhere(?array $u): bool
    {
        return $this->divisi->isHeadAnywhere($u);
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
        if ($div === Divisi::NONSHIFT) {
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
        // Key is `shifts` (not `shift`) — `shift` only as fallback for very old data.
        $set = $this->settingArr();
        $def = is_array($set['shifts'] ?? null) ? $set['shifts'] : (is_array($set['shift'] ?? null) ? $set['shift'] : []);

        if (self::onCore()) {
            [$toUlid] = $this->users();
            $sql = 'SELECT u.legacy_id AS user_id, s.tgl, s.shift, s.jam_mulai, s.jam_selesai
                     FROM `jadwal_sel` s JOIN `user` u ON u.id = s.user_id WHERE s.tgl BETWEEN ? AND ?';
            $par = [$a, $b];
            if ($user !== '') {
                if (! isset($toUlid[$user])) {
                    return ['dari' => $a, 'sampai' => $b, 'rows' => []];
                }
                $sql .= ' AND s.user_id = ?';
                $par[] = $toUlid[$user];
            }
            $cells = $this->db()->select($sql, $par);
        } else {
            $sql = 'SELECT `user_id`,`tgl`,`shift`,`jam_mulai`,`jam_selesai` FROM `jadwal_sel` WHERE `tgl` BETWEEN ? AND ?';
            $par = [$a, $b];
            if ($user !== '') {
                $sql .= ' AND `user_id` = ?';
                $par[] = $user;
            }
            $cells = $this->db()->select($sql, $par);
        }

        $rows = [];
        foreach ($cells as $r) {
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
        if (self::onCore()) {
            $rows = ($d !== '' && $sm !== '')
                ? $this->db()->select('SELECT u.legacy_id AS user_id, s.tgl, s.shift, s.jam_mulai, s.jam_selesai, s.catatan
                                        FROM `jadwal_sel` s JOIN `user` u ON u.id = s.user_id
                                        WHERE s.tgl BETWEEN ? AND ?', [$d, $sm])
                : $this->db()->select('SELECT u.legacy_id AS user_id, s.tgl, s.shift, s.jam_mulai, s.jam_selesai, s.catatan
                                        FROM `jadwal_sel` s JOIN `user` u ON u.id = s.user_id');
        } else {
            $cols = '`user_id`,`tgl`,`shift`,`jam_mulai`,`jam_selesai`,`catatan`';
            $rows = ($d !== '' && $sm !== '')
                ? $this->db()->select("SELECT $cols FROM `jadwal_sel` WHERE `tgl` BETWEEN ? AND ?", [$d, $sm])
                : $this->db()->select("SELECT $cols FROM `jadwal_sel`");
        }
        $sel = [];
        foreach ($rows as $r) {
            $sel[] = ['u' => $r->user_id, 'd' => $r->tgl, 't' => $r->shift, 'm' => $r->jam_mulai, 's' => $r->jam_selesai, 'n' => $r->catatan];
        }

        if (self::onCore()) {
            $aju = array_map(fn ($r) => $this->pengajuanJson((array) $r), $this->db()->select(
                "SELECT p.legacy_id AS id, u.legacy_id AS user_id, p.jenis, p.tgl_mulai, p.tgl_selesai, p.alasan, p.status,
                        p.head_at, p.head_oleh, p.shift, p.jam_mulai, p.jam_selesai, p.dibuat_at, p.dibuat_oleh,
                        p.putus_at, p.putus_oleh, p.putus_nota
                   FROM (
                  SELECT * FROM `jadwal_pengajuan` WHERE `status` IN ('MENUNGGU','MENUNGGU_HRD')
                  UNION ALL
                  SELECT * FROM (SELECT * FROM `jadwal_pengajuan` WHERE `status` NOT IN ('MENUNGGU','MENUNGGU_HRD')
                                 ORDER BY `putus_at` DESC LIMIT 200) x
                ) p JOIN `user` u ON u.id = p.user_id ORDER BY p.dibuat_at DESC"
            ));
        } else {
            $aju = array_map(fn ($r) => $this->pengajuanJson((array) $r), $this->db()->select(
                "SELECT * FROM (
                   SELECT * FROM `jadwal_pengajuan` WHERE `status` IN ('MENUNGGU','MENUNGGU_HRD')
                   UNION ALL
                   SELECT * FROM (SELECT * FROM `jadwal_pengajuan` WHERE `status` NOT IN ('MENUNGGU','MENUNGGU_HRD')
                                  ORDER BY `putus_at` DESC LIMIT 200) x
                 ) y ORDER BY `dibuat_at` DESC"
            ));
        }

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
        if (self::onCore()) {
            [$toUlid] = $this->users();
            $sql = 'SELECT p.legacy_id AS id, u.legacy_id AS user_id, p.jenis, p.tgl_mulai, p.tgl_selesai, p.alasan, p.status,
                           p.head_at, p.head_oleh, p.shift, p.jam_mulai, p.jam_selesai, p.dibuat_at, p.dibuat_oleh,
                           p.putus_at, p.putus_oleh, p.putus_nota
                    FROM `jadwal_pengajuan` p JOIN `user` u ON u.id = p.user_id WHERE 1 = 1';
            $par = [];
            if (($f['userId'] ?? '') !== '') {
                if (! isset($toUlid[$f['userId']])) {
                    return [];
                }
                $sql .= ' AND p.user_id = ?';
                $par[] = $toUlid[$f['userId']];
            }
        } else {
            $sql = 'SELECT * FROM `jadwal_pengajuan` WHERE 1 = 1';
            $par = [];
            if (($f['userId'] ?? '') !== '') {
                $sql .= ' AND `user_id` = ?';
                $par[] = $f['userId'];
            }
        }
        $st = array_values(array_filter(
            array_map(fn ($s) => strtoupper(trim((string) $s)), (array) ($f['status'] ?? [])),
            fn ($s) => $s !== ''
        ));
        if (count($st) > 0) {
            $alias = self::onCore() ? 'p.' : '';
            $sql .= " AND {$alias}`status` IN (".implode(',', array_fill(0, count($st), '?')).')';
            foreach ($st as $s) {
                $par[] = $s;
            }
        }
        $dari = self::tglValid($f['dari'] ?? '');
        $sampai = self::tglValid($f['sampai'] ?? '');
        if ($dari !== '' && $sampai !== '' && $sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $alias = self::onCore() ? 'p.' : '';
        if ($dari !== '') {
            $sql .= " AND {$alias}`tgl_selesai` >= ?";
            $par[] = $dari;
        }
        if ($sampai !== '') {
            $sql .= " AND {$alias}`tgl_mulai` <= ?";
            $par[] = $sampai;
        }
        $sql .= self::onCore() ? ' ORDER BY p.`dibuat_at` DESC' : ' ORDER BY `dibuat_at` DESC';

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
        if (self::onCore()) {
            $r = $this->db()->selectOne('SELECT p.legacy_id AS id, u.legacy_id AS user_id, p.jenis, p.tgl_mulai, p.tgl_selesai,
                    p.alasan, p.status, p.head_at, p.head_oleh, p.shift, p.jam_mulai, p.jam_selesai,
                    p.dibuat_at, p.dibuat_oleh, p.putus_at, p.putus_oleh, p.putus_nota
                FROM `jadwal_pengajuan` p JOIN `user` u ON u.id = p.user_id WHERE p.legacy_id = ?', [self::s($id)]);
        } else {
            $r = $this->db()->selectOne('SELECT * FROM `jadwal_pengajuan` WHERE `id` = ?', [self::s($id)]);
        }

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

        if (self::onCore()) {
            return $this->saveCellsCore($rows, $hapus, $by);
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

    /** Cells on core: legacy user ids translate to User ULIDs inside. */
    private function saveCellsCore(array $rows, array $hapus, string $by): array
    {
        [$toUlid] = $this->users();
        $actor = $this->actorUlid($by);
        $by = mb_substr(self::s($by), 0, 120);
        $now = self::now();
        $nIsi = 0;
        $nHapus = 0;
        $this->db()->transaction(function () use ($rows, $hapus, $toUlid, $actor, $now, &$nIsi, &$nHapus) {
            foreach ($rows as $r) {
                $r = (array) $r;
                $uid = mb_substr(self::s($r['u'] ?? ''), 0, 64);
                $d = self::tglValid($r['d'] ?? '');
                $t = mb_substr(self::s($r['t'] ?? ''), 0, 16);
                if ($uid === '' || $d === '' || $t === '') {
                    continue;
                }
                if (! isset($toUlid[$uid])) {
                    throw new RuntimeException('Pengguna tidak dikenal: '.$uid);
                }
                $this->db()->statement(
                    'INSERT INTO `jadwal_sel` (`id`,`legacy_id`,`user_id`,`tgl`,`shift`,`jam_mulai`,`jam_selesai`,`catatan`,
                        `created_by`,`updated_by`,`version`,`created_at`,`updated_at`)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE `shift`=VALUES(`shift`), `jam_mulai`=VALUES(`jam_mulai`),
                       `jam_selesai`=VALUES(`jam_selesai`), `catatan`=VALUES(`catatan`),
                       `updated_by`=VALUES(`updated_by`), `updated_at`=VALUES(`updated_at`), `version`=`version`+1',
                    [self::ulid(), $uid.'|'.$d, $toUlid[$uid], $d, $t, self::jamValid($r['m'] ?? ''), self::jamValid($r['s'] ?? ''),
                        mb_substr(self::s($r['n'] ?? ''), 0, 120), $actor, $actor, 1, $now, $now]
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
                if (! isset($toUlid[$uid])) {
                    // No rows can exist for an unknown User (FK); count the
                    // attempt like legacy does for its orphan delete.
                    $nHapus++;

                    continue;
                }
                $this->db()->delete('DELETE FROM `jadwal_sel` WHERE `user_id` = ? AND `tgl` = ?', [$toUlid[$uid], $d]);
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
        if (self::onCore()) {
            return $this->saveSettingCore((array) $data, $by);
        }
        $d = (array) $data;
        if (AccountRepository::onCore()) {
            // The blob is replaced whole, so absent maps mean "none" here too.
            app(CoreAccountRepository::class)->saveDivisiMaps(
                JsonDoc::toArray($d['heads'] ?? []), JsonDoc::toArray($d['divOverride'] ?? []), $by);
            unset($d['heads'], $d['divOverride']);
        }
        $this->divisiInputs = null;
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

    /**
     * Setting on core: split the blob into the normalised tables. The blob is
     * replaced whole, so a part absent from the input means "none" (its rows
     * are deleted) — same as legacy replacing the blob.
     */
    private function saveSettingCore(array $d, string $by): array
    {
        if (AccountRepository::onCore()) {
            app(CoreAccountRepository::class)->saveDivisiMaps(
                JsonDoc::toArray($d['heads'] ?? []), JsonDoc::toArray($d['divOverride'] ?? []), $by);
        }
        [$toUlid] = $this->users();
        $actor = $this->actorUlid($by);
        $now = self::now();
        $db = $this->db();

        $db->transaction(function () use ($d, $toUlid, $actor, $now) {
            // Shifts, keyed by code.
            $want = [];
            foreach (is_array($d['shifts'] ?? null) ? $d['shifts'] : [] as $kode => $def) {
                $kode = is_scalar($kode) ? trim((string) $kode) : '';
                if ($kode === '') {
                    continue;
                }
                $def = is_array($def) ? $def : [];
                $known = ['n', 'm', 's', 'w', 'libur', 'urut'];
                $str = fn (string $k, int $max) => array_key_exists($k, $def) && is_scalar($def[$k]) ? mb_substr((string) $def[$k], 0, $max) : null;
                $want[$kode] = [
                    'nama' => $str('n', 32),
                    'jam_mulai' => $str('m', 5),
                    'jam_selesai' => $str('s', 5),
                    'warna' => $str('w', 16),
                    'libur' => array_key_exists('libur', $def) ? (! empty($def['libur']) ? 1 : 0) : null,
                    'urutan' => array_key_exists('urut', $def) ? (int) $def['urut'] : null,
                    'ekstra' => ($x = array_diff_key($def, array_flip($known))) === []
                        ? null : json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
            $this->syncCore('jadwal_shift', 'kode', $want, $actor, $now);

            // Per-crew maps; blank values and unknown Users mean "none".
            $jab = [];
            foreach (is_array($d['jabatan'] ?? null) ? $d['jabatan'] : [] as $u => $j) {
                $u = is_scalar($u) ? trim((string) $u) : '';
                if ($u !== '' && isset($toUlid[$u]) && is_scalar($j) && trim((string) $j) !== '') {
                    $jab[$u] = ['user_id' => $toUlid[$u], 'jabatan' => mb_substr((string) $j, 0, 255)];
                }
            }
            $this->syncCore('jadwal_jabatan', 'legacy_id', $jab, $actor, $now);

            $kru = [];
            foreach (is_array($d['shiftKru'] ?? null) ? $d['shiftKru'] : [] as $u => $k) {
                $u = is_scalar($u) ? trim((string) $u) : '';
                if ($u !== '' && isset($toUlid[$u]) && is_scalar($k) && trim((string) $k) !== '') {
                    $kru[$u] = ['user_id' => $toUlid[$u], 'kode_shift' => mb_substr((string) $k, 0, 16)];
                }
            }
            $this->syncCore('jadwal_shift_kru', 'legacy_id', $kru, $actor, $now);

            $man = [];
            $pos = 0;
            foreach (is_array($d['manajemen'] ?? null) ? $d['manajemen'] : [] as $u) {
                $u = is_scalar($u) ? trim((string) $u) : '';
                if ($u !== '' && isset($toUlid[$u]) && ! isset($man[$u])) {
                    $man[$u] = ['user_id' => $toUlid[$u], 'urutan' => ++$pos];
                }
            }
            $this->syncCore('jadwal_manajemen', 'legacy_id', $man, $actor, $now);

            // Scalars + template/unknown keys.
            $knownTop = ['shifts', 'heads', 'divOverride', 'jabatan', 'shiftKru', 'maksBeruntun', 'jedaMin', 'manajemen'];
            $ekstra = array_diff_key($d, array_flip($knownTop));
            if (array_key_exists('template', $ekstra) && $ekstra['template'] === []) {
                $ekstra['template'] = new stdClass;
            }
            $this->syncCore('jadwal_pengaturan', 'legacy_id', ['1' => [
                'maks_beruntun' => array_key_exists('maksBeruntun', $d) ? (int) $d['maksBeruntun'] : null,
                'jeda_menit' => array_key_exists('jedaMin', $d) ? (int) $d['jedaMin'] : null,
                'ekstra' => $ekstra === [] ? null
                    : json_encode($ekstra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]], $actor, $now);
        });

        $this->settingArr = null;
        $this->divisiInputs = null;
        $this->userMaps = null;
        app(HeadDirectory::class)->flush();

        return ['saved' => true, 'ts' => gmdate('c')];
    }

    /**
     * Replace a core table's rows with $want (keyed by $key): insert missing
     * with version 1, update changed with version + 1, delete the rest.
     *
     * @param  array<string,array<string,mixed>>  $want
     */
    private function syncCore(string $table, string $key, array $want, ?string $actor, string $now): void
    {
        $db = $this->db();
        $have = [];
        foreach ($db->select("SELECT * FROM `$table`") as $r) {
            $have[(string) $r->{$key}] = $r;
        }
        foreach (array_diff_key($have, $want) as $k => $r) {
            $db->delete("DELETE FROM `$table` WHERE `$key` = ?", [$k]);
        }
        foreach ($want as $k => $cols) {
            if (! isset($have[$k])) {
                $db->table($table)->insert(['id' => self::ulid(), $key => $k, ...$cols,
                    'created_by' => $actor, 'updated_by' => $actor, 'version' => 1,
                    'created_at' => $now, 'updated_at' => $now]);

                continue;
            }
            $changed = [];
            foreach ($cols as $c => $v) {
                if (self::norm($have[$k]->{$c} ?? null) !== self::norm($v)) {
                    $changed[$c] = $v;
                }
            }
            if ($changed !== []) {
                $changed['updated_by'] = $actor;
                $changed['updated_at'] = $now;
                $changed['version'] = (int) $have[$k]->version + 1;
                $db->table($table)->where($key, $k)->update($changed);
            }
        }
    }

    private static function norm(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return (string) $v;
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
        $cols = [$id, $uid, $jenis, $a, $b, mb_substr(self::s($row['alasan'] ?? ''), 0, 2000),
            self::ms(), mb_substr(self::s($by), 0, 120),
            mb_substr(self::s($row['shift'] ?? ''), 0, 16),
            self::jamValid($row['jamMulai'] ?? ''), self::jamValid($row['jamSelesai'] ?? '')];

        if (self::onCore()) {
            [$toUlid] = $this->users();
            if (! isset($toUlid[$uid])) {
                throw new RuntimeException('Pengguna tidak dikenal: '.$uid);
            }
            $actor = $this->actorUlid($by);
            $now = self::now();
            $this->db()->statement(
                "INSERT INTO `jadwal_pengajuan`
                   (`id`,`legacy_id`,`user_id`,`jenis`,`tgl_mulai`,`tgl_selesai`,`alasan`,`status`,
                    `dibuat_at`,`dibuat_oleh`,`shift`,`jam_mulai`,`jam_selesai`,
                    `created_by`,`updated_by`,`version`,`created_at`,`updated_at`)
                 VALUES (?,?,?,?,?,?,?,'MENUNGGU',?,?,?,?,?,?,?,1,?,?)
                 ON DUPLICATE KEY UPDATE `jenis`=VALUES(`jenis`), `tgl_mulai`=VALUES(`tgl_mulai`),
                   `tgl_selesai`=VALUES(`tgl_selesai`), `alasan`=VALUES(`alasan`),
                   `shift`=VALUES(`shift`), `jam_mulai`=VALUES(`jam_mulai`), `jam_selesai`=VALUES(`jam_selesai`),
                   `updated_by`=VALUES(`updated_by`), `updated_at`=VALUES(`updated_at`), `version`=`version`+1",
                [self::ulid(), $id, $toUlid[$uid], $jenis, $a, $b, $cols[5], $cols[6], $cols[7], $cols[8], $cols[9], $cols[10],
                    $actor, $actor, $now, $now]
            );

            return ['saved' => true, 'id' => $id];
        }

        $this->db()->statement(
            "INSERT INTO `jadwal_pengajuan`
               (`id`,`user_id`,`jenis`,`tgl_mulai`,`tgl_selesai`,`alasan`,`status`,`dibuat_at`,`dibuat_oleh`,`shift`,`jam_mulai`,`jam_selesai`)
             VALUES (?,?,?,?,?,?,'MENUNGGU',?,?,?,?,?)
             ON DUPLICATE KEY UPDATE `jenis`=VALUES(`jenis`), `tgl_mulai`=VALUES(`tgl_mulai`),
               `tgl_selesai`=VALUES(`tgl_selesai`), `alasan`=VALUES(`alasan`),
               `shift`=VALUES(`shift`), `jam_mulai`=VALUES(`jam_mulai`), `jam_selesai`=VALUES(`jam_selesai`)",
            $cols
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

        if (self::onCore()) {
            $actor = $this->actorUlid($by);
            $now = self::now();
            $sql = $status === 'MENUNGGU_HRD'
                ? 'UPDATE `jadwal_pengajuan` SET `status`=?, `head_at`=?, `head_oleh`=?, `putus_nota`=?, `updated_by`=?, `updated_at`=?, `version`=`version`+1 WHERE `legacy_id`=?'
                : 'UPDATE `jadwal_pengajuan` SET `status`=?, `putus_at`=?, `putus_oleh`=?, `putus_nota`=?, `updated_by`=?, `updated_at`=?, `version`=`version`+1 WHERE `legacy_id`=?';
            $this->db()->update($sql, [$status, self::ms(), mb_substr(self::s($by), 0, 120), mb_substr(self::s($nota), 0, 255), $actor, $now, $id]);
        } else {
            $sql = $status === 'MENUNGGU_HRD'
                ? 'UPDATE `jadwal_pengajuan` SET `status`=?, `head_at`=?, `head_oleh`=?, `putus_nota`=? WHERE `id`=?'
                : 'UPDATE `jadwal_pengajuan` SET `status`=?, `putus_at`=?, `putus_oleh`=?, `putus_nota`=? WHERE `id`=?';
            $this->db()->update($sql, [$status, self::ms(), mb_substr(self::s($by), 0, 120), mb_substr(self::s($nota), 0, 255), $id]);
        }

        return ['saved' => true, 'id' => $id, 'status' => $status];
    }

    public function deleteRequest(string $id): array
    {
        if (self::onCore()) {
            $this->db()->delete('DELETE FROM `jadwal_pengajuan` WHERE `legacy_id` = ?', [self::s($id)]);
        } else {
            $this->db()->delete('DELETE FROM `jadwal_pengajuan` WHERE `id` = ?', [self::s($id)]);
        }

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
