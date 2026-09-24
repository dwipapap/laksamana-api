<?php

namespace App\Modules\Account\Services;

use App\Auth\LegacySessions;
use App\Auth\OfficeAccess;
use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;

/**
 * Office accounts & module access — port of account-mysql/lib_account_mysql.php.
 *
 * Every public method returns the SAME flat array the legacy aksi_* function
 * returned (error codes included), so the legacy controller can emit it
 * verbatim and v1 controllers can translate it. Rules live here only.
 *
 * Kept on purpose from legacy (see the long comments in the original file):
 *  - login by username OR name + PIN (username wins); PIN may repeat across users
 *  - name/username uniqueness is checked ACROSS both columns
 *  - saveUser writes pin+active always, username & HR columns only when sent
 *  - deleting requires the account to be deactivated first
 *  - the last superadmin cannot drop '*' from itself
 *  - roster managers (admins of `jadwal`, incl. Tim HRD) may edit identity
 *    columns only, and their saves keep the OLD pin/active
 */
class AccountService
{
    public function __construct(
        private readonly OfficeAccess $access,
        private readonly LegacySessions $sessions,
    ) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('account');
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || strtoupper(self::s($v)) === 'TRUE';
    }

    public static function hrValue(string $key, mixed $v): string
    {
        $v = self::s($v);
        if ($key === 'joinDate') {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        }

        return mb_substr($v, 0, 120);
    }

    public static function hrJson(array $u): array
    {
        $out = [];
        foreach (OfficeAccess::HR_COLUMNS as $key => $col) {
            $out[$key] = isset($u[$col]) ? self::s($u[$col]) : '';
        }

        return $out;
    }

    public static function usernameValid(string $v): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]{3,40}$/', $v);
    }

    /** @return array<int,array<string,mixed>> real rows only (no half-empty ghosts) */
    public function allUsers(): array
    {
        return array_map(fn ($r) => (array) $r, $this->db()->select(
            'SELECT '.OfficeAccess::userColumnsSql()." FROM `users`
             WHERE TRIM(id) <> '' AND TRIM(name) <> '' ORDER BY name ASC"
        ));
    }

    public function identityClash(string $cand, string $exceptId): ?array
    {
        $c = mb_strtolower(trim(self::s($cand)), 'UTF-8');
        if ($c === '') {
            return null;
        }
        $r = $this->db()->selectOne(
            "SELECT id, name, username FROM `users`
             WHERE id <> ? AND (LOWER(TRIM(name)) = ? OR (TRIM(username) <> '' AND LOWER(TRIM(username)) = ?))
             LIMIT 1",
            [self::s($exceptId), $c, $c]
        );

        return $r ? (array) $r : null;
    }

    // ------------------------------------------------------------ gates

    /** callerName + callerPin must be a superadmin. */
    public function superadmin(array $body): ?array
    {
        $caller = $this->access->userByCredentials(self::s($body['callerName'] ?? ''), self::s($body['callerPin'] ?? ''));
        if (! $caller) {
            return null;
        }

        return $this->access->isSuperadmin($caller['id']) ? $caller : null;
    }

    public function moduleAdminByPin(array $body, string $module): ?array
    {
        $caller = $this->access->userByCredentials(self::s($body['callerName'] ?? ''), self::s($body['callerPin'] ?? ''));
        if (! $caller) {
            return null;
        }

        return $this->access->isModuleAdmin($caller['id'], $module) ? $caller : null;
    }

    /** Roster manager = admin of `jadwal` (Tim HRD included), proven by the session token. */
    public function rosterManager(array $body): ?array
    {
        $u = $this->sessions->user(self::s($body['sesi'] ?? ''));
        if (! $u) {
            return null;
        }

        return $this->access->isModuleAdmin($u['id'], 'jadwal') ? $u : null;
    }

    // ------------------------------------------------------------ auth

    public function login(string $name, string $pin): array
    {
        $name = self::s($name);
        $pin = self::s($pin);
        if ($name === '' || $pin === '') {
            return ['ok' => false, 'error' => 'missing'];
        }
        $u = $this->access->userByCredentials($name, $pin);
        if (! $u) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $id = self::s($u['id']) !== '' ? self::s($u['id']) : ('u-'.mb_strtolower($name, 'UTF-8'));

        return ['ok' => true, 'user' => [
            'id' => $id,
            'name' => self::s($u['name']),
            'username' => self::s($u['username']),
            'keterangan' => self::s($u['keterangan']),
            'modules' => $this->access->modules($u['id']),
            'adminModules' => $this->access->adminModules($u['id']),
            'token' => $this->sessions->create($u['id']),
        ]];
    }

    public function whoami(array $body): array
    {
        $u = $this->sessions->user(self::s($body['token'] ?? ''));
        if (! $u) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }

        return ['ok' => true, 'user' => $this->access->profile($u)];
    }

    public function logout(array $body): array
    {
        $this->sessions->revoke(self::s($body['token'] ?? ''));

        return ['ok' => true];
    }

    public function changePin(array $body): array
    {
        $name = self::s($body['name'] ?? '');
        if ($name === '') {
            return ['ok' => false, 'error' => 'missing'];
        }
        $next = self::s($body['newPin'] ?? '');
        if (! preg_match('/^\d{4,8}$/', $next)) {
            return ['ok' => false, 'error' => 'bad_pin'];
        }
        $n = $this->db()->update('UPDATE `users` SET pin = ? WHERE LOWER(TRIM(name)) = ? AND active = 1',
            [$next, mb_strtolower($name, 'UTF-8')]);
        if ($n > 0) {
            return ['ok' => true];
        }

        return $this->access->userByCredentials($name, $next)
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'not_found'];
    }

    public function setUsername(array $body): array
    {
        $name = self::s($body['name'] ?? '');
        $pin = self::s($body['pin'] ?? '');
        if ($name === '' || $pin === '') {
            return ['ok' => false, 'error' => 'missing'];
        }
        $u = $this->access->userByCredentials($name, $pin);
        if (! $u) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->applyUsername(self::s($u['id']), trim(self::s($body['username'] ?? '')));
    }

    /** Shared by legacy setUsername and v1 PATCH /me/username (row chosen by the server). */
    public function applyUsername(string $userId, string $new): array
    {
        if ($new === '') {
            $this->db()->update("UPDATE `users` SET username = '' WHERE id = ?", [$userId]);

            return ['ok' => true, 'username' => ''];
        }
        if (! self::usernameValid($new)) {
            return ['ok' => false, 'error' => 'bad_username'];
        }
        if ($this->identityClash($new, $userId)) {
            return ['ok' => false, 'error' => 'username_taken'];
        }
        $this->db()->update('UPDATE `users` SET username = ? WHERE id = ?', [$new, $userId]);
        $this->access->forgetUser($userId);

        return ['ok' => true, 'username' => $new];
    }

    public function sessionRefresh(array $body): array
    {
        $id = self::s($body['userId'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'error' => 'missing'];
        }
        $u = $this->access->userById($id);
        if (! $u) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ((int) $u['active'] !== 1) {
            return ['ok' => false, 'error' => 'inactive'];
        }
        $p = $this->access->profile($u);
        unset($p['headDivisi']);

        return ['ok' => true, 'user' => $p];
    }

    // ------------------------------------------------------------ users (superadmin)

    public function userSummary(array $u, bool $withSecrets): array
    {
        $row = array_merge(self::hrJson($u), [
            'id' => self::s($u['id']),
            'name' => self::s($u['name']),
        ]);
        if ($withSecrets) {
            $row['pin'] = self::s($u['pin']);
        }

        return array_merge($row, [
            'active' => (int) $u['active'] === 1,
            'keterangan' => self::s($u['keterangan']),
            'noHp' => self::s($u['no_hp']),
            'talentaId' => self::s($u['talenta_id']),
            'username' => self::s($u['username']),
            'modules' => $this->access->modules($u['id']),
            'grants' => $this->access->rawGrants($u['id'], 1),
            'denies' => $this->access->rawGrants($u['id'], 0),
            'adminModules' => $this->access->adminModules($u['id']),
        ]);
    }

    public function listUsers(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->usersPayload(true);
    }

    public function usersPayload(bool $withSecrets): array
    {
        $users = array_map(fn ($u) => $this->userSummary($u, $withSecrets), $this->allUsers());

        return ['ok' => true, 'users' => $users, 'modules' => $this->access->activeModules()]
            + $this->rulePayload();
    }

    public function rulePayload(): array
    {
        return [
            'bawaan' => OfficeAccess::aturanBawaan(),
            'terbatas' => OfficeAccess::aturanTerbatas(),
            'adminBawaan' => OfficeAccess::aturanAdminBawaan(),
        ];
    }

    public function saveUser(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->saveUserCore($body);
    }

    /**
     * saveUser WITHOUT the gate. `pin` and `active` are ALWAYS written on update
     * (empty pin => '1111', missing active => active). Callers that do not hold
     * the old PIN must inject it (see rosterSaveUser).
     */
    public function saveUserCore(array $body): array
    {
        $name = self::s($body['name'] ?? '');
        $pin = self::s($body['pin'] ?? '');
        if ($pin === '') {
            $pin = '1111';
        }
        $ket = self::s($body['keterangan'] ?? '');
        $hp = self::s($body['noHp'] ?? '');
        $tid = self::s($body['talentaId'] ?? '');
        $active = (array_key_exists('active', $body) && $body['active'] === false) ? 0 : 1;
        if ($name === '') {
            return ['ok' => false, 'error' => 'missing_fields'];
        }
        $editId = self::s($body['id'] ?? '');

        if ($c = $this->identityClash($name, $editId)) {
            return ['ok' => false, 'error' => 'name_taken', 'takenBy' => self::s($c['name'])];
        }
        if ($tid !== '') {
            $d = $this->db()->selectOne('SELECT id, name FROM `users` WHERE TRIM(talenta_id) = ? AND id <> ? LIMIT 1', [$tid, $editId]);
            if ($d) {
                return ['ok' => false, 'error' => 'talenta_taken', 'takenBy' => self::s($d->name)];
            }
        }

        if ($editId !== '') {
            $set = ['name = ?', 'pin = ?', 'active = ?', 'keterangan = ?', 'no_hp = ?', 'talenta_id = ?'];
            $arg = [$name, $pin, $active, $ket, $hp, $tid];
            if (array_key_exists('username', $body)) {
                $un = trim(self::s($body['username']));
                if ($un !== '') {
                    if (! self::usernameValid($un)) {
                        return ['ok' => false, 'error' => 'bad_username'];
                    }
                    if ($du = $this->identityClash($un, $editId)) {
                        return ['ok' => false, 'error' => 'username_taken', 'takenBy' => self::s($du['name'])];
                    }
                }
                $set[] = 'username = ?';
                $arg[] = $un;
            }
            foreach (OfficeAccess::HR_COLUMNS as $key => $col) {
                if (! array_key_exists($key, $body)) {
                    continue;
                }
                $set[] = "`$col` = ?";
                $arg[] = self::hrValue($key, $body[$key]);
            }
            $arg[] = $editId;
            $n = $this->db()->update('UPDATE `users` SET '.implode(', ', $set).' WHERE id = ?', $arg);
            $this->access->forgetUser($editId);
            if ($n === 0 && ! $this->access->userById($editId)) {
                return ['ok' => false, 'error' => 'not_found'];
            }

            return ['ok' => true, 'id' => $editId];
        }

        $base = 'u-'.preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name, 'UTF-8'));
        if ($base === 'u-') {
            $base = 'u-user';
        }
        $id = $base;
        $n = 2;
        while ($this->access->userById($id)) {
            $this->access->forgetUser($id);
            $id = $base.$n++;
        }
        $this->access->forgetUser($id);
        $cols = ['id', 'name', 'pin', 'active', 'keterangan', 'no_hp', 'talenta_id'];
        $vals = [$id, $name, $pin, $active, $ket, $hp, $tid];
        foreach (OfficeAccess::HR_COLUMNS as $key => $col) {
            $cols[] = "`$col`";
            $vals[] = array_key_exists($key, $body) ? self::hrValue($key, $body[$key]) : '';
        }
        $this->db()->insert('INSERT INTO `users` ('.implode(', ', $cols).') VALUES ('
            .implode(', ', array_fill(0, count($cols), '?')).')', $vals);

        return ['ok' => true, 'id' => $id];
    }

    public function saveUsers(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $rows = is_array($body['users'] ?? null) ? $body['users'] : [];
        if (! count($rows)) {
            return ['ok' => false, 'error' => 'empty'];
        }
        if (count($rows) > 500) {
            return ['ok' => false, 'error' => 'too_many'];
        }
        $out = [];
        $ok = 0;
        $fail = 0;
        foreach ($rows as $r) {
            $r = (array) $r;
            $baris = (int) ($r['_baris'] ?? 0);
            unset($r['_baris']);
            $r['callerName'] = $body['callerName'] ?? '';
            $r['callerPin'] = $body['callerPin'] ?? '';
            $h = $this->saveUser($r);
            if (! empty($h['ok'])) {
                $ok++;
            } else {
                $fail++;
                $out[] = ['baris' => $baris, 'nama' => self::s($r['name'] ?? ''),
                    'error' => $h['error'] ?? 'gagal', 'takenBy' => $h['takenBy'] ?? ''];
            }
        }

        return ['ok' => true, 'sukses' => $ok, 'gagal' => $fail, 'baris' => $out];
    }

    public function deleteUser(array $body): array
    {
        $caller = $this->superadmin($body);
        if (! $caller) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->deleteUserCore(self::s($body['id'] ?? ''), self::s($caller['id']), false);
    }

    /**
     * Permanent delete of a DEACTIVATED account (+ its grants and admins, so a
     * reused id never inherits them). $protectSuperadmin: roster managers may
     * not delete a superadmin.
     */
    public function deleteUserCore(string $id, string $callerId, bool $protectSuperadmin): array
    {
        if ($protectSuperadmin && $id === '') {
            return ['ok' => false, 'error' => 'missing_fields'];
        }
        if ($id !== '' && $callerId === $id && ! $protectSuperadmin) {
            return ['ok' => false, 'error' => 'cannot_delete_self'];
        }
        $u = $this->access->userById($id);
        if (! $u) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($protectSuperadmin) {
            if ($callerId === $id) {
                return ['ok' => false, 'error' => 'cannot_delete_self'];
            }
            if ($this->access->isSuperadmin($id)) {
                return ['ok' => false, 'error' => 'cannot_delete_admin'];
            }
        }
        if (! ((int) $u['active'] === 0)) {
            return ['ok' => false, 'error' => 'must_deactivate_first'];
        }
        $this->db()->transaction(function () use ($id) {
            $this->db()->delete('DELETE FROM `grants` WHERE user_id = ?', [$id]);
            $this->db()->delete('DELETE FROM `admins` WHERE user_id = ?', [$id]);
            $this->db()->delete('DELETE FROM `users` WHERE id = ?', [$id]);
        });
        $this->access->forgetUser($id);

        return $protectSuperadmin
            ? ['ok' => true, 'id' => $id, 'nama' => $u['name'] ?? '']
            : ['ok' => true];
    }

    // ------------------------------------------------------------ roster managers

    public function rosterSaveUser(array $body): array
    {
        if (! $this->rosterManager($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->rosterSaveUserCore($body);
    }

    /** Whitelisted identity columns only; keeps the OLD pin and (unless sent) active. */
    public function rosterSaveUserCore(array $body): array
    {
        $clean = [];
        foreach (array_merge(['id', 'name', 'keterangan', 'noHp', 'talentaId', 'active'], array_keys(OfficeAccess::HR_COLUMNS)) as $k) {
            if (array_key_exists($k, $body)) {
                $clean[$k] = $body[$k];
            }
        }
        $editId = self::s($clean['id'] ?? '');
        if ($editId !== '') {
            $old = $this->access->userById($editId);
            if (! $old) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            $clean['pin'] = self::s($old['pin']);
            if (! array_key_exists('active', $clean)) {
                $clean['active'] = (int) $old['active'] === 1;
            }
        }

        return $this->saveUserCore($clean);
    }

    public function rosterSetActive(array $body): array
    {
        $caller = $this->rosterManager($body);
        if (! $caller) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->setActiveCore(self::s($caller['id']), self::s($body['id'] ?? ''),
            ! (array_key_exists('active', $body) && $body['active'] === false));
    }

    public function setActiveCore(string $callerId, string $id, bool $active): array
    {
        if ($id === '') {
            return ['ok' => false, 'error' => 'missing_fields'];
        }
        if (! $this->access->userById($id)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (! $active && $callerId === $id) {
            return ['ok' => false, 'error' => 'cannot_deactivate_self'];
        }
        if (! $active && $this->access->isSuperadmin($id)) {
            return ['ok' => false, 'error' => 'cannot_deactivate_admin'];
        }
        $this->db()->update('UPDATE `users` SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
        $this->access->forgetUser($id);

        return ['ok' => true, 'id' => $id, 'active' => $active];
    }

    public function rosterDeleteUser(array $body): array
    {
        $caller = $this->rosterManager($body);
        if (! $caller) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->deleteUserCore(self::s($body['id'] ?? ''), self::s($caller['id']), true);
    }

    // ------------------------------------------------------------ modules registry

    public function listModules(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return ['ok' => true, 'modules' => $this->modulesList(($body['all'] ?? null) === true)] + $this->rulePayload();
    }

    public function modulesList(bool $all): array
    {
        $sql = 'SELECT `key`, `label`, `active` FROM `modules` '.($all ? '' : 'WHERE active = 1 ').'ORDER BY urut ASC, `key` ASC';
        $out = [];
        foreach ($this->db()->select($sql) as $m) {
            if (self::s($m->key) === '') {
                continue;
            }
            $out[] = ['key' => self::s($m->key), 'label' => self::s($m->label), 'active' => (int) $m->active === 1];
        }

        return $out;
    }

    /** Adds NEW keys only; never overwrites label/active, never deletes. */
    public function syncModules(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $incoming = is_array($body['modules'] ?? null) ? $body['modules'] : [];
        $urut = (int) ($this->db()->selectOne('SELECT COALESCE(MAX(urut),0) m FROM `modules`')->m ?? 0);
        $added = [];
        foreach ($incoming as $m) {
            $m = (array) $m;
            $key = self::s($m['key'] ?? '');
            if ($key === '' || $this->db()->selectOne('SELECT 1 x FROM `modules` WHERE `key` = ? LIMIT 1', [$key])) {
                continue;
            }
            $label = self::s($m['label'] ?? '') ?: $key;
            $urut += 10;
            $this->db()->insert('INSERT INTO `modules` (`key`, `label`, `active`, `urut`) VALUES (?, ?, 1, ?)', [$key, $label, $urut]);
            $added[] = $key;
        }

        return ['ok' => true, 'added' => $added];
    }

    public function saveModule(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $key = self::s($body['key'] ?? '');
        if ($key === '') {
            return ['ok' => false, 'error' => 'missing_key'];
        }
        if (! $this->db()->selectOne('SELECT 1 x FROM `modules` WHERE `key` = ? LIMIT 1', [$key])) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (isset($body['label'])) {
            $this->db()->update('UPDATE `modules` SET label = ? WHERE `key` = ?', [self::s($body['label']), $key]);
        }
        if (isset($body['active'])) {
            $this->db()->update('UPDATE `modules` SET active = ? WHERE `key` = ?', [self::truthy($body['active']) ? 1 : 0, $key]);
        }

        return ['ok' => true];
    }

    public function setAdmin(array $body): array
    {
        $caller = $this->superadmin($body);
        if (! $caller) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $userId = self::s($body['userId'] ?? '');
        $module = self::s($body['module'] ?? '');
        if ($userId === '' || $module === '') {
            return ['ok' => false, 'error' => 'missing_fields'];
        }
        $grant = self::truthy($body['access'] ?? null);
        if ($module === '*' && ! $grant && self::s($caller['id']) === $userId) {
            $n = (int) $this->db()->selectOne("SELECT COUNT(*) c FROM `admins` WHERE `module` = '*'")->c;
            if ($n <= 1) {
                return ['ok' => false, 'error' => 'last_superadmin'];
            }
        }
        if ($grant) {
            $this->db()->insert('INSERT IGNORE INTO `admins` (user_id, `module`) VALUES (?, ?)', [$userId, $module]);
        } else {
            $this->db()->delete('DELETE FROM `admins` WHERE user_id = ? AND `module` = ?', [$userId, $module]);
        }
        $this->access->forgetUser($userId);

        return ['ok' => true];
    }

    public function listAccess(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $users = [];
        foreach ($this->allUsers() as $u) {
            $users[] = [
                'id' => self::s($u['id']),
                'name' => self::s($u['name']),
                'keterangan' => self::s($u['keterangan']),
                'active' => (int) $u['active'] === 1,
                'modules' => $this->access->modules($u['id']),
                'adminModules' => $this->access->adminModules($u['id']),
            ];
        }

        return ['ok' => true, 'users' => $users, 'modules' => $this->access->activeModules()] + $this->rulePayload();
    }

    public function setModuleAccess(array $body): array
    {
        $caller = $this->superadmin($body);
        if (! $caller) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->setModuleAccessCore(self::s($caller['id']), self::s($body['userId'] ?? ''),
            self::s($body['module'] ?? ''), self::truthy($body['access'] ?? null));
    }

    public function setModuleAccessCore(string $callerId, string $userId, string $module, bool $access): array
    {
        if ($userId === '' || $module === '') {
            return ['ok' => false, 'error' => 'missing_fields'];
        }
        $this->db()->statement(
            'INSERT INTO `grants` (user_id, `module`, `access`, granted_by, ts) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE `access` = VALUES(`access`), granted_by = VALUES(granted_by), ts = VALUES(ts)',
            [$userId, $module, $access ? 1 : 0, $callerId]
        );
        $this->access->forgetUser($userId);

        return ['ok' => true];
    }

    // ------------------------------------------------------------ rosters (open reads)

    public function listModuleMembers(array $body): array
    {
        $module = self::s($body['module'] ?? '');
        if (! $this->moduleAdminByPin($body, $module)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return ['ok' => true, 'members' => $this->moduleMembers($module, false)];
    }

    public function listModuleRoster(array $body): array
    {
        $module = self::s($body['module'] ?? '');
        if ($module === '') {
            return ['ok' => false, 'error' => 'missing_module'];
        }

        return ['ok' => true, 'members' => $this->moduleMembers($module, true)];
    }

    public function moduleMembers(string $module, bool $withAdminFlag): array
    {
        $out = [];
        foreach ($this->allUsers() as $u) {
            if (! in_array($module, $this->access->modules($u['id']), true)) {
                continue;
            }
            $row = array_merge(self::hrJson($u), [
                'id' => self::s($u['id']),
                'name' => self::s($u['name']),
                'keterangan' => self::s($u['keterangan']),
                'noHp' => self::s($u['no_hp']),
                'talentaId' => self::s($u['talenta_id']),
                'username' => self::s($u['username']),
                'active' => (int) $u['active'] === 1,
            ]);
            if ($withAdminFlag) {
                $row['isModuleAdmin'] = $this->access->isModuleAdmin($u['id'], $module);
            }
            $out[] = $row;
        }

        return $out;
    }

    public function listDivisiRoster(): array
    {
        return ['ok' => true, 'members' => $this->divisiRoster()];
    }

    /** All users + Tim + HR columns, never the PIN. */
    public function divisiRoster(): array
    {
        $out = [];
        foreach ($this->allUsers() as $u) {
            $out[] = array_merge(self::hrJson($u), [
                'id' => self::s($u['id']),
                'name' => self::s($u['name']),
                'keterangan' => self::s($u['keterangan']),
                'noHp' => self::s($u['no_hp']),
                'talentaId' => self::s($u['talenta_id']),
                'active' => (int) $u['active'] === 1,
            ]);
        }

        return $out;
    }

    // ------------------------------------------------------------ one-time import (idempotent)

    public function import(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $n = ['users' => 0, 'modules' => 0, 'grants' => 0, 'admins' => 0];
        $bool = function ($v, $default = true) {
            if ($v === null || $v === '') {
                return $default;
            }
            if (is_bool($v)) {
                return $v;
            }
            $t = strtoupper(trim((string) $v));

            return ! in_array($t, ['FALSE', '0', 'NO', 'TIDAK'], true);
        };
        $db = $this->db();
        foreach (is_array($body['users'] ?? null) ? $body['users'] : [] as $u) {
            $u = (array) $u;
            $id = self::s($u['id'] ?? '');
            $nm = self::s($u['name'] ?? '');
            if ($id === '' || $nm === '') {
                continue;
            }
            $db->statement("INSERT INTO `users` (id, name, pin, active, keterangan, talenta_id) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name), pin=VALUES(pin), active=VALUES(active),
                  keterangan=VALUES(keterangan), talenta_id=IF(VALUES(talenta_id)='', talenta_id, VALUES(talenta_id))",
                [$id, $nm, self::s($u['pin'] ?? '') !== '' ? self::s($u['pin']) : '1111',
                    $bool($u['active'] ?? null) ? 1 : 0, self::s($u['keterangan'] ?? ''), self::s($u['talentaId'] ?? '')]);
            $n['users']++;
        }
        foreach (is_array($body['modules'] ?? null) ? $body['modules'] : [] as $m) {
            $m = (array) $m;
            $k = self::s($m['key'] ?? '');
            if ($k === '') {
                continue;
            }
            $db->statement('INSERT INTO `modules` (`key`,`label`,`active`) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE `label`=VALUES(`label`), `active`=VALUES(`active`)',
                [$k, self::s($m['label'] ?? '') !== '' ? self::s($m['label']) : $k, $bool($m['active'] ?? null) ? 1 : 0]);
            $n['modules']++;
        }
        foreach (is_array($body['grants'] ?? null) ? $body['grants'] : [] as $g) {
            $g = (array) $g;
            $u = self::s($g['userId'] ?? '');
            $m = self::s($g['module'] ?? '');
            if ($u === '' || $m === '') {
                continue;
            }
            $db->statement('INSERT INTO `grants` (user_id,`module`,`access`,granted_by,ts) VALUES (?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE `access`=VALUES(`access`)',
                [$u, $m, $bool($g['access'] ?? null) ? 1 : 0, self::s($g['grantedBy'] ?? 'import')]);
            $n['grants']++;
        }
        foreach (is_array($body['admins'] ?? null) ? $body['admins'] : [] as $a) {
            $a = (array) $a;
            $u = self::s($a['userId'] ?? '');
            $m = self::s($a['module'] ?? '');
            if ($u === '' || $m === '') {
                continue;
            }
            $db->insert('INSERT IGNORE INTO `admins` (user_id,`module`) VALUES (?,?)', [$u, $m]);
            $n['admins']++;
        }

        return ['ok' => true, 'diproses' => $n];
    }

    /** Seed three superadmins ONLY when `users` is completely empty (fresh install). */
    public function seedIfEmpty(): bool
    {
        $db = $this->db();
        if ((int) $db->selectOne('SELECT COUNT(*) c FROM `users`')->c > 0) {
            return false;
        }
        foreach ([['u-admin', 'Admin'], ['u-howandi', 'Howandi'], ['u-wandi', 'Wandi']] as [$id, $name]) {
            $db->insert("INSERT IGNORE INTO `users` (id,name,pin,active,keterangan) VALUES (?,?,'1111',1,'')", [$id, $name]);
            $db->insert("INSERT IGNORE INTO `grants` (user_id,`module`,`access`,granted_by) VALUES (?,'*',1,'seed')", [$id]);
            $db->insert("INSERT IGNORE INTO `admins` (user_id,`module`) VALUES (?,'*')", [$id]);
        }
        $db->insert("INSERT IGNORE INTO `grants` (user_id,`module`,`access`,granted_by) VALUES ('u-wandi','howandi_life',0,'seed')");

        return true;
    }

    // ------------------------------------------------------------ diagnostics

    public function ping(): array
    {
        return ['pong' => true, 'backend' => 'laravel', 'env' => Modules::envLabel(),
            'db' => Modules::databaseName('account'), 'ts' => gmdate('c')];
    }

    public function stats(): array
    {
        $out = ['backend' => 'laravel', 'env' => Modules::envLabel(), 'db' => Modules::databaseName('account')];
        foreach (['users', 'modules', 'grants', 'admins'] as $t) {
            $out[$t] = (int) $this->db()->selectOne("SELECT COUNT(*) c FROM `$t`")->c;
        }
        $out['superadmin'] = (int) $this->db()->selectOne("SELECT COUNT(*) c FROM `admins` WHERE `module` = '*'")->c;
        $out['ts'] = gmdate('c');

        return $out;
    }
}
