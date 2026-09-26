<?php

namespace App\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * AccountRepository on the identity tables of `core` (#44, docs/db/identity.md).
 *
 * Bound instead of the legacy repository when DB_ACCOUNT_CONNECTION=core.
 * Every method keeps the legacy row shape and ids: `id` is `user.legacy_id`,
 * `username`/`join_date` are '' when NULL, `*` is `modul_id NULL`, and grants
 * are rebuilt from Izin Akses + Larangan. Legacy ids stay the currency of every
 * compat route and every unmigrated module; the ULID is exposed only by v1.
 *
 * Also owns the Divisi SQL (words, Kepala Divisi, Penempatan Divisi) that
 * lived in `jadwal_setting` before the cutover.
 *
 * Deviations the FKs force (legacy stored orphans silently): a grant/admin
 * for an unknown User or Modul is ignored, a Larangan on `*` only removes the
 * Izin Akses for `*`, a non-calendar join date is stored as NULL, and a Kepala
 * Divisi cannot be deleted (AccountService reports `is_kepala_divisi`).
 */
class CoreAccountRepository extends AccountRepository
{
    private const USER = "u.legacy_id AS id, u.nama AS name, u.pin, u.aktif AS active, u.tim AS keterangan,
        u.no_hp, u.talenta_id, COALESCE(u.username, '') AS username, u.cabang AS branch,
        u.organisasi AS organization, u.jabatan AS job_position, u.level_jabatan AS job_level,
        u.status_kerja AS employment_status, COALESCE(DATE_FORMAT(u.tanggal_bergabung, '%Y-%m-%d'), '') AS join_date";

    /** legacy users column => core user column */
    private const COLUMNS = ['id' => 'legacy_id', 'name' => 'nama', 'pin' => 'pin', 'active' => 'aktif',
        'keterangan' => 'tim', 'no_hp' => 'no_hp', 'talenta_id' => 'talenta_id', 'username' => 'username',
        'branch' => 'cabang', 'organization' => 'organisasi', 'job_position' => 'jabatan',
        'job_level' => 'level_jabatan', 'employment_status' => 'status_kerja', 'join_date' => 'tanggal_bergabung'];

    private static function now(): string
    {
        return Carbon::now('UTC')->format('Y-m-d H:i:s');
    }

    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** Legacy value -> core value for one users column. */
    private static function coreValue(string $col, mixed $v): mixed
    {
        return match ($col) {
            'username' => self::s($v) === '' ? null : self::s($v),
            'join_date' => self::date(self::s($v)),
            'active' => (int) $v ? 1 : 0,
            default => $v,
        };
    }

    private static function date(string $v): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
    }

    public function userUlid(string $legacyId): ?string
    {
        return $this->db()->selectOne('SELECT id FROM `user` WHERE legacy_id = ?', [self::s($legacyId)])?->id;
    }

    private function modulUlid(string $key): ?string
    {
        return $this->db()->selectOne('SELECT id FROM `modul` WHERE kunci = ?', [self::s($key)])?->id;
    }

    // ---------------------------------------------------------------- users

    public function userById(string $id): ?array
    {
        $r = $this->db()->selectOne('SELECT '.self::USER.' FROM `user` u WHERE u.legacy_id = ? LIMIT 1', [self::s($id)]);

        return $r ? (array) $r : null;
    }

    public function userByCredentials(string $login, string $pin): ?array
    {
        $u = mb_strtolower(trim(self::s($login)), 'UTF-8');
        if ($u === '') {
            return null;
        }
        $pin = self::s($pin);
        $r = $this->db()->selectOne('SELECT '.self::USER." FROM `user` u
             WHERE TRIM(COALESCE(u.username, '')) <> '' AND LOWER(TRIM(u.username)) = ? AND TRIM(u.pin) = ? AND u.aktif = 1
             ORDER BY u.legacy_id LIMIT 1", [$u, $pin]);
        $r ??= $this->db()->selectOne('SELECT '.self::USER.' FROM `user` u
             WHERE LOWER(TRIM(u.nama)) = ? AND TRIM(u.pin) = ? AND u.aktif = 1 ORDER BY u.legacy_id LIMIT 1', [$u, $pin]);

        return $r ? (array) $r : null;
    }

    public function allUsers(): array
    {
        return array_map(fn ($r) => (array) $r, $this->db()->select(
            'SELECT '.self::USER." FROM `user` u WHERE TRIM(u.legacy_id) <> '' AND TRIM(u.nama) <> '' ORDER BY u.nama ASC"
        ));
    }

    public function identityClash(string $cand, string $exceptId): ?array
    {
        $c = mb_strtolower(trim(self::s($cand)), 'UTF-8');
        if ($c === '') {
            return null;
        }
        $r = $this->db()->selectOne(
            "SELECT legacy_id AS id, nama AS name, COALESCE(username, '') AS username FROM `user`
             WHERE legacy_id <> ? AND (LOWER(TRIM(nama)) = ? OR (TRIM(COALESCE(username, '')) <> '' AND LOWER(TRIM(username)) = ?))
             ORDER BY legacy_id LIMIT 1",
            [self::s($exceptId), $c, $c]
        );

        return $r ? (array) $r : null;
    }

    public function talentaOwner(string $tid, string $exceptId): ?array
    {
        $r = $this->db()->selectOne('SELECT legacy_id AS id, nama AS name FROM `user` WHERE TRIM(talenta_id) = ? AND legacy_id <> ?
             ORDER BY legacy_id LIMIT 1', [self::s($tid), self::s($exceptId)]);

        return $r ? (array) $r : null;
    }

    /** UPDATE on `user` with the technical columns; returns affected rows. */
    private function updateWhere(array $set, string $where, array $args): int
    {
        $sql = [];
        $vals = [];
        foreach ($set as $col => $v) {
            $sql[] = "`$col` = ?";
            $vals[] = $v;
        }
        $sql[] = '`updated_at` = ?';
        $vals[] = self::now();

        return $this->db()->update('UPDATE `user` SET '.implode(', ', $sql).', `version` = `version` + 1 WHERE '.$where,
            [...$vals, ...$args]);
    }

    public function updatePinByName(string $name, string $pin): int
    {
        return $this->updateWhere(['pin' => self::s($pin)], 'LOWER(TRIM(nama)) = ? AND aktif = 1',
            [mb_strtolower(self::s($name), 'UTF-8')]);
    }

    public function updatePinById(string $id, string $pin): void
    {
        $this->updateWhere(['pin' => self::s($pin)], 'legacy_id = ?', [self::s($id)]);
    }

    public function updateUsername(string $id, string $username): void
    {
        $this->updateWhere(['username' => self::coreValue('username', $username)], 'legacy_id = ?', [self::s($id)]);
    }

    public function setActive(string $id, bool $active): void
    {
        $this->updateWhere(['aktif' => $active ? 1 : 0], 'legacy_id = ?', [self::s($id)]);
    }

    public function updateUser(string $id, array $columns): int
    {
        $set = [];
        foreach ($columns as $col => $val) {
            if ($col !== 'id' && isset(self::COLUMNS[$col])) {
                $set[self::COLUMNS[$col]] = self::coreValue($col, $val);
            }
        }

        return $set === [] ? 0 : $this->updateWhere($set, 'legacy_id = ?', [self::s($id)]);
    }

    public function insertUser(array $columns): void
    {
        $row = ['id' => self::ulid(), 'version' => 1, 'created_at' => self::now(), 'updated_at' => self::now()];
        foreach ($columns as $col => $val) {
            if (isset(self::COLUMNS[$col])) {
                $row[self::COLUMNS[$col]] = self::coreValue($col, $val);
            }
        }
        $this->db()->table('user')->insert($row);
    }

    public function deleteUserCascade(string $id): void
    {
        // izin_akses, larangan, admin_modul, penempatan_divisi and sesi_legacy cascade.
        $this->db()->delete('DELETE FROM `user` WHERE legacy_id = ?', [self::s($id)]);
    }

    /** @return array<int,string> Divisi codes this User heads (blocks deleting them). */
    public function kepalaDivisiOf(string $id): array
    {
        return array_map(fn ($r) => (string) $r->kode, $this->db()->select(
            'SELECT d.kode FROM kepala_divisi k JOIN divisi d ON d.id = k.divisi_id JOIN `user` u ON u.id = k.user_id
             WHERE u.legacy_id = ? ORDER BY d.urutan', [self::s($id)]));
    }

    public function userCount(): int
    {
        return (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `user`')->c;
    }

    public function countTable(string $table): int
    {
        $sql = match ($table) {
            'users' => 'SELECT COUNT(*) c FROM `user`',
            'modules' => 'SELECT COUNT(*) c FROM `modul`',
            'grants' => 'SELECT (SELECT COUNT(*) FROM izin_akses) + (SELECT COUNT(*) FROM larangan) c',
            'admins' => 'SELECT COUNT(*) c FROM admin_modul',
            default => null,
        };

        return $sql === null ? 0 : (int) $this->db()->selectOne($sql)->c;
    }

    public function activeUserSample(bool $superadmin): ?array
    {
        $isSuper = 'SELECT a.user_id FROM admin_modul a WHERE a.modul_id IS NULL';
        $r = $this->db()->selectOne('SELECT u.legacy_id AS id, u.nama AS name, u.pin FROM `user` u WHERE u.aktif = 1 AND u.id '
            .($superadmin ? 'IN' : 'NOT IN')." ($isSuper) ORDER BY u.legacy_id LIMIT 1");

        return $r ? (array) $r : null;
    }

    public function tokenOwner(string $id): AccountUser
    {
        return AccountUser::query()->where('legacy_id', self::s($id))->firstOrFail();
    }

    // --------------------------------------------------------------- grants

    /** Izin Akses (access 1) + Larangan (access 0) as legacy grant rows, ordered like the legacy PK. */
    private function grantSelect(string $where): string
    {
        return "SELECT * FROM (
                  SELECT COALESCE(m.kunci, '*') AS module, 1 AS access FROM izin_akses g
                    JOIN `user` u ON u.id = g.user_id LEFT JOIN modul m ON m.id = g.modul_id WHERE $where
                  UNION ALL
                  SELECT m.kunci AS module, 0 AS access FROM larangan g
                    JOIN `user` u ON u.id = g.user_id JOIN modul m ON m.id = g.modul_id WHERE $where
                ) x";
    }

    public function grantRows(string $userId): array
    {
        $uid = self::s($userId);
        $out = [];
        foreach ($this->db()->select($this->grantSelect('u.legacy_id = ?').' ORDER BY module', [$uid, $uid]) as $g) {
            $out[] = ['module' => self::s($g->module), 'access' => (int) $g->access];
        }

        return $out;
    }

    public function grantModulesByAccess(string $userId, int $access): array
    {
        $uid = self::s($userId);
        $out = [];
        foreach ($this->db()->select($this->grantSelect('u.legacy_id = ?').' WHERE access = ? ORDER BY module', [$uid, $uid, $access]) as $r) {
            if (self::s($r->module) !== '') {
                $out[] = self::s($r->module);
            }
        }

        return $out;
    }

    /**
     * One grant decision: Izin Akses and Larangan are exclusive per (User, Modul).
     * $overwrite false = INSERT IGNORE semantics (an existing decision wins).
     */
    private function putGrant(string $userId, string $module, bool $access, string $grantedBy, bool $overwrite, bool $touchActor = true): void
    {
        $u = $this->userUlid($userId);
        $isAll = self::s($module) === '*';
        $m = $isAll ? null : $this->modulUlid($module);
        if ($u === null || (! $isAll && $m === null)) {
            return; // FK: no orphan grants in core
        }
        $legacy = self::s($userId).'|'.self::s($module);
        $existing = $this->db()->selectOne('SELECT 1 x FROM izin_akses WHERE legacy_id = ? UNION ALL SELECT 1 FROM larangan WHERE legacy_id = ?', [$legacy, $legacy]);
        if ($existing && ! $overwrite) {
            return;
        }
        $keep = $access ? 'izin_akses' : 'larangan';
        $drop = $access ? 'larangan' : 'izin_akses';
        $this->db()->transaction(function () use ($u, $m, $legacy, $keep, $drop, $grantedBy, $isAll, $access, $touchActor) {
            $this->db()->delete("DELETE FROM `$drop` WHERE legacy_id = ?", [$legacy]);
            if ($isAll && ! $access) {
                return; // a Larangan on every Modul does nothing in the rules; core cannot store it
            }
            $by = $this->userUlid($grantedBy);
            $now = self::now();
            $row = $this->db()->selectOne("SELECT id FROM `$keep` WHERE legacy_id = ?", [$legacy]);
            if ($row) {
                if ($touchActor) {
                    $this->db()->update("UPDATE `$keep` SET updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ?", [$by, $now, $row->id]);
                }

                return;
            }
            $this->db()->table($keep)->insert(['id' => self::ulid(), 'legacy_id' => $legacy, 'user_id' => $u, 'modul_id' => $m,
                'created_by' => $by, 'updated_by' => $by, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        });
    }

    public function upsertGrant(string $userId, string $module, bool $access, string $grantedBy): void
    {
        $this->putGrant($userId, $module, $access, $grantedBy, true);
    }

    public function insertIgnoreGrant(string $userId, string $module, int $access, string $grantedBy): void
    {
        $this->putGrant($userId, $module, $access === 1, $grantedBy, false);
    }

    public function upsertImportGrant(string $userId, string $module, bool $access, string $grantedBy): void
    {
        // legacy ON DUPLICATE KEY UPDATE access only: granted_by stays the first writer's
        $this->putGrant($userId, $module, $access, $grantedBy, true, false);
    }

    // --------------------------------------------------------------- admins

    public function adminModules(string $userId): array
    {
        $out = [];
        foreach ($this->db()->select("SELECT COALESCE(m.kunci, '*') AS module FROM admin_modul a JOIN `user` u ON u.id = a.user_id
             LEFT JOIN modul m ON m.id = a.modul_id WHERE u.legacy_id = ? ORDER BY module", [self::s($userId)]) as $r) {
            if (self::s($r->module) !== '') {
                $out[] = self::s($r->module);
            }
        }

        return $out;
    }

    public function countSuperadmins(): int
    {
        return (int) $this->db()->selectOne('SELECT COUNT(*) c FROM admin_modul WHERE modul_id IS NULL')->c;
    }

    public function grantAdmin(string $userId, string $module): void
    {
        $u = $this->userUlid($userId);
        $isAll = self::s($module) === '*';
        $m = $isAll ? null : $this->modulUlid($module);
        if ($u === null || (! $isAll && $m === null)) {
            return;
        }
        $this->db()->statement('INSERT IGNORE INTO admin_modul (id, legacy_id, user_id, modul_id, version, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, ?, ?)', [self::ulid(), self::s($userId).'|'.self::s($module), $u, $m, self::now(), self::now()]);
    }

    public function revokeAdmin(string $userId, string $module): void
    {
        $this->db()->delete('DELETE FROM admin_modul WHERE legacy_id = ?', [self::s($userId).'|'.self::s($module)]);
    }

    // -------------------------------------------------------------- modules

    public function activeModuleKeys(): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT kunci FROM modul WHERE aktif = 1 ORDER BY urutan ASC, kunci ASC') as $r) {
            if (self::s($r->kunci) !== '') {
                $out[] = self::s($r->kunci);
            }
        }

        return $out;
    }

    public function moduleRows(bool $all): array
    {
        $sql = 'SELECT kunci, label, aktif FROM modul '.($all ? '' : 'WHERE aktif = 1 ').'ORDER BY urutan ASC, kunci ASC';
        $out = [];
        foreach ($this->db()->select($sql) as $m) {
            if (self::s($m->kunci) === '') {
                continue;
            }
            $out[] = ['key' => self::s($m->kunci), 'label' => self::s($m->label), 'active' => (int) $m->aktif === 1];
        }

        return $out;
    }

    public function moduleExists(string $key): bool
    {
        return $this->modulUlid($key) !== null;
    }

    public function maxModuleUrut(): int
    {
        return (int) ($this->db()->selectOne('SELECT COALESCE(MAX(urutan),0) m FROM modul')->m ?? 0);
    }

    public function insertModule(string $key, string $label, int $urut): void
    {
        $this->db()->table('modul')->insert(['id' => self::ulid(), 'kunci' => self::s($key), 'label' => self::s($label),
            'aktif' => 1, 'urutan' => $urut, 'version' => 1, 'created_at' => self::now(), 'updated_at' => self::now()]);
    }

    public function upsertImportModule(string $key, string $label, bool $active): void
    {
        if ($this->moduleExists($key)) {
            $this->db()->update('UPDATE modul SET label = ?, aktif = ?, updated_at = ?, version = version + 1 WHERE kunci = ?',
                [self::s($label), $active ? 1 : 0, self::now(), self::s($key)]);

            return;
        }
        $this->db()->table('modul')->insert(['id' => self::ulid(), 'kunci' => self::s($key), 'label' => self::s($label),
            'aktif' => $active ? 1 : 0, 'urutan' => 0, 'version' => 1, 'created_at' => self::now(), 'updated_at' => self::now()]);
    }

    public function updateModuleLabel(string $key, string $label): void
    {
        $this->db()->update('UPDATE modul SET label = ?, updated_at = ?, version = version + 1 WHERE kunci = ?', [self::s($label), self::now(), self::s($key)]);
    }

    public function updateModuleActive(string $key, bool $active): void
    {
        $this->db()->update('UPDATE modul SET aktif = ?, updated_at = ?, version = version + 1 WHERE kunci = ?', [$active ? 1 : 0, self::now(), self::s($key)]);
    }

    // ------------------------------------------------------------- sessions

    private static function utcMs(int $ms): string
    {
        return Carbon::createFromTimestampMs($ms, 'UTC')->format('Y-m-d H:i:s.v');
    }

    public function pruneExpiredSessions(int $nowMs): void
    {
        $this->db()->delete('DELETE FROM sesi_legacy WHERE kedaluwarsa < ?', [self::utcMs($nowMs)]);
    }

    public function insertSession(string $token, string $userId, int $expiryMs): void
    {
        $u = $this->userUlid($userId) ?? throw new \RuntimeException('unknown user');
        $this->db()->table('sesi_legacy')->insert(['token' => $token, 'user_id' => $u, 'kedaluwarsa' => self::utcMs($expiryMs),
            'created_at' => self::now(), 'updated_at' => self::now()]);
    }

    public function findSession(string $token): ?array
    {
        $r = $this->db()->selectOne('SELECT u.legacy_id AS user_id, s.kedaluwarsa FROM sesi_legacy s JOIN `user` u ON u.id = s.user_id
            WHERE s.token = ? LIMIT 1', [trim($token)]);

        return $r ? ['user_id' => $r->user_id,
            'expiry' => Carbon::createFromFormat('Y-m-d H:i:s.v', substr((string) $r->kedaluwarsa, 0, 23), 'UTC')->getTimestampMs()] : null;
    }

    public function deleteSession(string $token): void
    {
        $t = trim($token);
        if ($t !== '') {
            $this->db()->delete('DELETE FROM sesi_legacy WHERE token = ?', [$t]);
        }
    }

    // ----------------------------------------------------------- one-time import

    public function upsertImportUser(string $id, string $name, string $pin, bool $active, string $ket, string $tid): void
    {
        if ($this->userUlid($id) === null) {
            $this->insertUser(['id' => $id, 'name' => $name, 'pin' => $pin, 'active' => $active ? 1 : 0,
                'keterangan' => $ket, 'talenta_id' => $tid]);

            return;
        }
        $cols = ['name' => $name, 'pin' => $pin, 'active' => $active ? 1 : 0, 'keterangan' => $ket];
        if (self::s($tid) !== '') {
            $cols['talenta_id'] = $tid;
        }
        $this->updateUser($id, $cols);
    }

    public function insertIgnoreUser(string $id, string $name): void
    {
        if ($this->userUlid($id) === null) {
            $this->insertUser(['id' => $id, 'name' => $name, 'pin' => '1111', 'active' => 1, 'keterangan' => '']);
        }
    }

    // ---------------------------------------------------------------- Divisi

    /** @return array{0: array<string,array<int,string>>, 1: array<int,string>} [synonyms by Divisi order, office words] */
    public function divisiWords(): array
    {
        $syn = [];
        $office = [];
        foreach ($this->db()->select('SELECT d.kode, k.kata FROM divisi_kata k LEFT JOIN divisi d ON d.id = k.divisi_id
            ORDER BY d.urutan, k.created_at, k.id') as $r) {
            if ($r->kode === null) {
                $office[] = (string) $r->kata;
            } else {
                $syn[(string) $r->kode][] = (string) $r->kata;
            }
        }

        return [$syn, $office];
    }

    /** @return array<string,array<int,string>> the legacy `heads` map: divisi => [userId…] */
    public function headsByDivisi(): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT d.kode, u.legacy_id FROM kepala_divisi k JOIN divisi d ON d.id = k.divisi_id
            JOIN `user` u ON u.id = k.user_id ORDER BY d.urutan, k.urutan, k.id') as $r) {
            $out[(string) $r->kode][] = (string) $r->legacy_id;
        }

        return $out;
    }

    /** @return array<string,string> the legacy `divOverride` map: userId => divisi | nonshift */
    public function penempatanMap(): array
    {
        $out = [];
        foreach ($this->db()->select("SELECT u.legacy_id, COALESCE(d.kode, 'nonshift') AS kode FROM penempatan_divisi p
            JOIN `user` u ON u.id = p.user_id LEFT JOIN divisi d ON d.id = p.divisi_id ORDER BY p.id") as $r) {
            $out[(string) $r->legacy_id] = (string) $r->kode;
        }

        return $out;
    }

    private function divisiUlid(string $kode): string
    {
        $id = $this->db()->selectOne('SELECT id FROM divisi WHERE kode = ?', [$kode])?->id;
        if ($id === null) {
            $id = self::ulid();
            $urut = (int) $this->db()->selectOne('SELECT COALESCE(MAX(urutan), 0) m FROM divisi')->m + 1;
            $this->db()->table('divisi')->insert(['id' => $id, 'kode' => $kode, 'urutan' => $urut, 'version' => 1,
                'created_at' => self::now(), 'updated_at' => self::now()]);
        }

        return $id;
    }

    /**
     * Replace Kepala Divisi and Penempatan Divisi with the maps the jadwal
     * setting screen saves. Ids of Users that do not exist are dropped (FK).
     *
     * @param  array<string,mixed>  $heads  divisi => [userId…]
     * @param  array<string,mixed>  $override  userId => divisi | nonshift
     */
    public function saveDivisiMaps(array $heads, array $override, string $by): void
    {
        $this->db()->transaction(function () use ($heads, $override, $by) {
            $actor = null;
            foreach ($this->db()->select('SELECT id FROM `user` WHERE nama = ? ORDER BY legacy_id LIMIT 1', [self::s($by)]) as $r) {
                $actor = $r->id;
            }
            $users = [];
            foreach ($this->db()->select('SELECT id, legacy_id FROM `user`') as $r) {
                $users[(string) $r->legacy_id] = $r->id;
            }
            $now = self::now();

            $want = [];
            foreach ($heads as $div => $list) {
                $pos = 0;
                foreach (is_array($list) ? $list : [] as $uid) {
                    $uid = is_scalar($uid) ? trim((string) $uid) : '';
                    if ($uid !== '' && isset($users[$uid]) && ! isset($want[$div.'|'.$uid])) {
                        $want[$div.'|'.$uid] = [(string) $div, $users[$uid], ++$pos];
                    }
                }
            }
            $have = [];
            foreach ($this->db()->select('SELECT id, legacy_id, urutan FROM kepala_divisi') as $r) {
                $have[(string) $r->legacy_id] = $r;
            }
            foreach (array_diff_key($have, $want) as $r) {
                $this->db()->delete('DELETE FROM kepala_divisi WHERE id = ?', [$r->id]);
            }
            foreach ($want as $key => [$div, $uid, $pos]) {
                if (isset($have[$key])) {
                    if ((int) $have[$key]->urutan !== $pos) {
                        $this->db()->update('UPDATE kepala_divisi SET urutan = ?, updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ?',
                            [$pos, $actor, $now, $have[$key]->id]);
                    }

                    continue;
                }
                $this->db()->table('kepala_divisi')->insert(['id' => self::ulid(), 'legacy_id' => $key, 'divisi_id' => $this->divisiUlid($div),
                    'user_id' => $uid, 'urutan' => $pos, 'created_by' => $actor, 'updated_by' => $actor, 'version' => 1,
                    'created_at' => $now, 'updated_at' => $now]);
            }

            $wantP = [];
            foreach ($override as $uid => $div) {
                $div = is_scalar($div) ? trim((string) $div) : '';
                if ($div !== '' && isset($users[(string) $uid])) {
                    $wantP[(string) $uid] = $div === 'nonshift' ? null : $this->divisiUlid($div);
                }
            }
            $haveP = [];
            foreach ($this->db()->select('SELECT id, legacy_id, divisi_id FROM penempatan_divisi') as $r) {
                $haveP[(string) $r->legacy_id] = $r;
            }
            foreach (array_diff_key($haveP, $wantP) as $r) {
                $this->db()->delete('DELETE FROM penempatan_divisi WHERE id = ?', [$r->id]);
            }
            foreach ($wantP as $uid => $divId) {
                if (isset($haveP[$uid])) {
                    if ($haveP[$uid]->divisi_id !== $divId) {
                        $this->db()->update('UPDATE penempatan_divisi SET divisi_id = ?, updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ?',
                            [$divId, $actor, $now, $haveP[$uid]->id]);
                    }

                    continue;
                }
                $this->db()->table('penempatan_divisi')->insert(['id' => self::ulid(), 'legacy_id' => $uid, 'user_id' => $users[$uid],
                    'divisi_id' => $divId, 'created_by' => $actor, 'updated_by' => $actor, 'version' => 1,
                    'created_at' => $now, 'updated_at' => $now]);
            }
        });
    }
}
