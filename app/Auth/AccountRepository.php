<?php

namespace App\Auth;

use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;

/**
 * The ONE place that runs SQL against the account database (`users`,
 * `grants`, `admins`, `sessions`, `modules`).
 *
 * Everything else — OfficeAccess (rules), LegacySessions (token lifecycle),
 * AccountService (business rules), controllers, tests — talks to the account
 * store through here and never touches its tables directly. When identity
 * cuts over to `core` (ADR-0002), this is the single class that gets a new
 * implementation; every caller keeps working unchanged.
 *
 * Methods are row-level storage operations only. Access RULES (who may open
 * or administer a module) stay in OfficeAccess; token/session LIFECYCLE
 * rules stay in LegacySessions; request validation and response shapes stay
 * in AccountService and the controllers. Strings are trimmed on the way in,
 * like the legacy lib — callers compare exact values.
 */
class AccountRepository
{
    /** Identity cut over (#44): the account Modul reads and writes the `core` identity tables. */
    public static function onCore(): bool
    {
        return Modules::connectionName('account') === 'core';
    }

    /** The User's ULID; null until identity is on core (legacy rows have none). */
    public function userUlid(string $legacyId): ?string
    {
        return null;
    }

    protected function db(): ConnectionInterface
    {
        return Modules::db('account');
    }

    protected static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    public static function userColumnsSql(): string
    {
        return 'id, name, pin, active, keterangan, no_hp, talenta_id, username, '
            .implode(', ', array_values(OfficeAccess::HR_COLUMNS));
    }

    // ---------------------------------------------------------------- users

    /** @return array<string,mixed>|null */
    public function userById(string $id): ?array
    {
        $r = $this->db()->selectOne('SELECT '.self::userColumnsSql().' FROM `users` WHERE id = ? LIMIT 1', [self::s($id)]);

        return $r ? (array) $r : null;
    }

    /**
     * Name OR username + PIN, active only. Username first; an empty username
     * never matches (otherwise an empty login would grab the first user
     * without a username).
     *
     * @return array<string,mixed>|null
     */
    public function userByCredentials(string $login, string $pin): ?array
    {
        $u = mb_strtolower(trim(self::s($login)), 'UTF-8');
        if ($u === '') {
            return null;
        }
        $pin = self::s($pin);
        $cols = 'id, name, pin, active, keterangan, talenta_id, username';

        $r = $this->db()->selectOne(
            "SELECT $cols FROM `users`
             WHERE TRIM(username) <> '' AND LOWER(TRIM(username)) = ? AND TRIM(pin) = ? AND active = 1 LIMIT 1",
            [$u, $pin]
        );
        if ($r) {
            return (array) $r;
        }
        $r = $this->db()->selectOne(
            "SELECT $cols FROM `users` WHERE LOWER(TRIM(name)) = ? AND TRIM(pin) = ? AND active = 1",
            [$u, $pin]
        );

        return $r ? (array) $r : null;
    }

    /** @return array<int,array<string,mixed>> real rows only (no half-empty ghosts) */
    public function allUsers(): array
    {
        return array_map(fn ($r) => (array) $r, $this->db()->select(
            'SELECT '.self::userColumnsSql()." FROM `users`
             WHERE TRIM(id) <> '' AND TRIM(name) <> '' ORDER BY name ASC"
        ));
    }

    /**
     * Cross-column clash: candidate name/username against everyone else's
     * `name` OR non-blank `username` (lowercased).
     *
     * @return array<string,mixed>|null the clashing row (id, name, username)
     */
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

    /** @return array<string,mixed>|null owner (id, name) of a non-blank talenta id */
    public function talentaOwner(string $tid, string $exceptId): ?array
    {
        $r = $this->db()->selectOne('SELECT id, name FROM `users` WHERE TRIM(talenta_id) = ? AND id <> ? LIMIT 1',
            [self::s($tid), self::s($exceptId)]);

        return $r ? (array) $r : null;
    }

    /** Legacy changePin: by name, active only. Returns affected rows. */
    public function updatePinByName(string $name, string $pin): int
    {
        return $this->db()->update('UPDATE `users` SET pin = ? WHERE LOWER(TRIM(name)) = ? AND active = 1',
            [self::s($pin), mb_strtolower(self::s($name), 'UTF-8')]);
    }

    /** v1 changePin: by id (the caller is already authenticated). */
    public function updatePinById(string $id, string $pin): void
    {
        $this->db()->update('UPDATE `users` SET pin = ? WHERE id = ?', [self::s($pin), self::s($id)]);
    }

    public function updateUsername(string $id, string $username): void
    {
        $this->db()->update('UPDATE `users` SET username = ? WHERE id = ?', [self::s($username), self::s($id)]);
    }

    public function setActive(string $id, bool $active): void
    {
        $this->db()->update('UPDATE `users` SET active = ? WHERE id = ?', [$active ? 1 : 0, self::s($id)]);
    }

    /**
     * Dynamic user update. Keys are column names (validated against the
     * users table); values are stored as given. Returns affected rows.
     */
    public function updateUser(string $id, array $columns): int
    {
        $allowed = ['name', 'pin', 'active', 'keterangan', 'no_hp', 'talenta_id', 'username',
            ...array_values(OfficeAccess::HR_COLUMNS)];
        $set = [];
        $arg = [];
        foreach ($columns as $col => $val) {
            if (! in_array($col, $allowed, true)) {
                continue;
            }
            $set[] = "`$col` = ?";
            $arg[] = $val;
        }
        if ($set === []) {
            return 0;
        }
        $arg[] = self::s($id);

        return $this->db()->update('UPDATE `users` SET '.implode(', ', $set).' WHERE id = ?', $arg);
    }

    /** Dynamic user insert. Keys are column names (same allowlist as updateUser, plus `id`). */
    public function insertUser(array $columns): void
    {
        $allowed = ['id', 'name', 'pin', 'active', 'keterangan', 'no_hp', 'talenta_id', 'username',
            ...array_values(OfficeAccess::HR_COLUMNS)];
        $cols = [];
        $vals = [];
        foreach ($columns as $col => $val) {
            if (! in_array($col, $allowed, true)) {
                continue;
            }
            $cols[] = "`$col`";
            $vals[] = $val;
        }
        $this->db()->insert('INSERT INTO `users` ('.implode(', ', $cols).') VALUES ('
            .implode(', ', array_fill(0, count($cols), '?')).')', $vals);
    }

    /** Permanent delete of a user plus its grants and admins (one transaction). */
    public function deleteUserCascade(string $id): void
    {
        $id = self::s($id);
        $this->db()->transaction(function () use ($id) {
            $this->db()->delete('DELETE FROM `grants` WHERE user_id = ?', [$id]);
            $this->db()->delete('DELETE FROM `admins` WHERE user_id = ?', [$id]);
            $this->db()->delete('DELETE FROM `users` WHERE id = ?', [$id]);
        });
    }

    public function userCount(): int
    {
        return (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `users`')->c;
    }

    public function countTable(string $table): int
    {
        if (! in_array($table, ['users', 'modules', 'grants', 'admins'], true)) {
            return 0;
        }

        return (int) $this->db()->selectOne("SELECT COUNT(*) c FROM `$table`")->c;
    }

    /** Active user sample for tests (id, name, pin). Superadmin or not. */
    public function activeUserSample(bool $superadmin): ?array
    {
        $r = $superadmin
            ? $this->db()->selectOne("SELECT u.id, u.name, u.pin FROM users u JOIN admins a ON a.user_id = u.id WHERE a.module = '*' AND u.active = 1 LIMIT 1")
            : $this->db()->selectOne("SELECT u.id, u.name, u.pin FROM users u WHERE u.active = 1 AND u.id NOT IN (SELECT user_id FROM admins WHERE module = '*') LIMIT 1");

        return $r ? (array) $r : null;
    }

    /** Eloquent owner for Sanctum token issuance (legacy-row identity). */
    public function tokenOwner(string $id): AccountUser
    {
        return AccountUser::query()->findOrFail(self::s($id));
    }

    // --------------------------------------------------------------- grants

    /** @return array<int,array{module:string,access:int}> every grant row of a user */
    public function grantRows(string $userId): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT `module`, `access` FROM `grants` WHERE user_id = ?', [self::s($userId)]) as $g) {
            $out[] = ['module' => self::s($g->module), 'access' => (int) $g->access];
        }

        return $out;
    }

    /** @return array<int,string> grant modules filtered by access (for the Kelola Akses checkboxes) */
    public function grantModulesByAccess(string $userId, int $access): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT `module` FROM `grants` WHERE user_id = ? AND access = ?', [self::s($userId), $access]) as $r) {
            if (self::s($r->module) !== '') {
                $out[] = self::s($r->module);
            }
        }

        return $out;
    }

    public function upsertGrant(string $userId, string $module, bool $access, string $grantedBy): void
    {
        $this->db()->statement(
            'INSERT INTO `grants` (user_id, `module`, `access`, granted_by, ts) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE `access` = VALUES(`access`), granted_by = VALUES(granted_by), ts = VALUES(ts)',
            [self::s($userId), self::s($module), $access ? 1 : 0, self::s($grantedBy)]
        );
    }

    public function insertIgnoreGrant(string $userId, string $module, int $access, string $grantedBy): void
    {
        $this->db()->insert('INSERT IGNORE INTO `grants` (user_id, `module`, `access`, granted_by) VALUES (?, ?, ?, ?)',
            [self::s($userId), self::s($module), $access, self::s($grantedBy)]);
    }

    // --------------------------------------------------------------- admins

    /** @return array<int,string> modules a user administers ('*' = superadmin) */
    public function adminModules(string $userId): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT `module` FROM `admins` WHERE user_id = ?', [self::s($userId)]) as $r) {
            if (self::s($r->module) !== '') {
                $out[] = self::s($r->module);
            }
        }

        return $out;
    }

    public function countSuperadmins(): int
    {
        return (int) $this->db()->selectOne("SELECT COUNT(*) c FROM `admins` WHERE `module` = '*'")->c;
    }

    public function grantAdmin(string $userId, string $module): void
    {
        $this->db()->insert('INSERT IGNORE INTO `admins` (user_id, `module`) VALUES (?, ?)', [self::s($userId), self::s($module)]);
    }

    public function revokeAdmin(string $userId, string $module): void
    {
        $this->db()->delete('DELETE FROM `admins` WHERE user_id = ? AND `module` = ?', [self::s($userId), self::s($module)]);
    }

    // -------------------------------------------------------------- modules

    /** @return array<int,string> keys of active modules, in display order */
    public function activeModuleKeys(): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT `key` FROM `modules` WHERE active = 1 ORDER BY urut ASC, `key` ASC') as $r) {
            if (self::s($r->key) !== '') {
                $out[] = self::s($r->key);
            }
        }

        return $out;
    }

    /** @return array<int,array{key:string,label:string,active:bool}> */
    public function moduleRows(bool $all): array
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

    public function moduleExists(string $key): bool
    {
        return (bool) $this->db()->selectOne('SELECT 1 x FROM `modules` WHERE `key` = ? LIMIT 1', [self::s($key)]);
    }

    public function maxModuleUrut(): int
    {
        return (int) ($this->db()->selectOne('SELECT COALESCE(MAX(urut),0) m FROM `modules`')->m ?? 0);
    }

    public function insertModule(string $key, string $label, int $urut): void
    {
        $this->db()->insert('INSERT INTO `modules` (`key`, `label`, `active`, `urut`) VALUES (?, ?, 1, ?)',
            [self::s($key), self::s($label), $urut]);
    }

    public function upsertImportModule(string $key, string $label, bool $active): void
    {
        $this->db()->statement('INSERT INTO `modules` (`key`,`label`,`active`) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE `label`=VALUES(`label`), `active`=VALUES(`active`)',
            [self::s($key), self::s($label), $active ? 1 : 0]);
    }

    public function updateModuleLabel(string $key, string $label): void
    {
        $this->db()->update('UPDATE `modules` SET label = ? WHERE `key` = ?', [self::s($label), self::s($key)]);
    }

    public function updateModuleActive(string $key, bool $active): void
    {
        $this->db()->update('UPDATE `modules` SET active = ? WHERE `key` = ?', [$active ? 1 : 0, self::s($key)]);
    }

    // ------------------------------------------------------------- sessions

    public function pruneExpiredSessions(int $nowMs): void
    {
        $this->db()->delete('DELETE FROM `sessions` WHERE expiry < ?', [$nowMs]);
    }

    public function insertSession(string $token, string $userId, int $expiryMs): void
    {
        $this->db()->insert('INSERT INTO `sessions` (token, user_id, expiry) VALUES (?, ?, ?)',
            [$token, trim($userId), $expiryMs]);
    }

    /** @return array<string,mixed>|null session row (user_id, expiry) */
    public function findSession(string $token): ?array
    {
        $r = $this->db()->selectOne('SELECT user_id, expiry FROM `sessions` WHERE token = ? LIMIT 1', [trim($token)]);

        return $r ? (array) $r : null;
    }

    public function deleteSession(string $token): void
    {
        $t = trim($token);
        if ($t !== '') {
            $this->db()->delete('DELETE FROM `sessions` WHERE token = ?', [$t]);
        }
    }

    // ----------------------------------------------------------- one-time import

    public function upsertImportUser(string $id, string $name, string $pin, bool $active, string $ket, string $tid): void
    {
        $this->db()->statement("INSERT INTO `users` (id, name, pin, active, keterangan, talenta_id) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE name=VALUES(name), pin=VALUES(pin), active=VALUES(active),
              keterangan=VALUES(keterangan), talenta_id=IF(VALUES(talenta_id)='', talenta_id, VALUES(talenta_id))",
            [self::s($id), self::s($name), self::s($pin), $active ? 1 : 0, self::s($ket), self::s($tid)]);
    }

    public function upsertImportGrant(string $userId, string $module, bool $access, string $grantedBy): void
    {
        $this->db()->statement('INSERT INTO `grants` (user_id,`module`,`access`,granted_by,ts) VALUES (?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE `access`=VALUES(`access`)',
            [self::s($userId), self::s($module), $access ? 1 : 0, self::s($grantedBy)]);
    }

    public function insertIgnoreUser(string $id, string $name): void
    {
        $this->db()->insert("INSERT IGNORE INTO `users` (id,name,pin,active,keterangan) VALUES (?,?,'1111',1,'')",
            [self::s($id), self::s($name)]);
    }
}
