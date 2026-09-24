<?php

namespace App\Auth;

use App\Modules\Jadwal\Services\HeadDirectory;
use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * WHO MAY OPEN / ADMINISTER WHICH MODULE — port of account-mysql/lib_account_mysql.php
 * (modul_untuk, admin_modul_untuk, modul_bawaan_untuk, aturan_terbatas, …).
 *
 * This is the single truth table for the whole API: Sanctum-authenticated v1
 * routes, the legacy session token routes, and the legacy account-api all
 * call these methods. Rules kept EXACTLY as in the legacy file, including
 * their order (see comments) — each one fixed a real incident there:
 *
 *  0. built-in access from the Tim column (whole-word match):
 *       jadwal  <- shift crew words | hrd/hr/ceo | admin of * or jadwal
 *       dw      <- hrd/hr/ceo | admin of * or dw | division head
 *  1. grants '*' access=1  -> every ACTIVE module
 *  2. per-module grants    -> access=1 adds, access=0 removes (also removes from '*')
 *  3. restricted modules (dw): removed unless allowed — EXCEPT an explicit
 *     per-module grant row is honoured; '*' alone never reaches dw.
 *
 * Admin modules = rows of `admins`, plus jadwal+dw for Tim hrd/hr (no deny).
 *
 * Failure policy (also legacy): errors while resolving built-in/restricted
 * access must never break login — built-in falls back to nothing, the
 * restricted check falls back to "keep".
 *
 * Registered as a scoped singleton: the per-request caches mirror the
 * `static $cache` arrays of the legacy functions.
 */
class OfficeAccess
{
    /** @var array<string,?array> */
    private array $userCache = [];

    /** @var array<string,array> */
    private array $adminCache = [];

    /** @var array<string,array> */
    private array $builtinCache = [];

    private ?array $activeModules = null;

    public function __construct(private readonly HeadDirectory $heads) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('account');
    }

    private static function s(mixed $v): string
    {
        return is_array($v) || is_object($v) ? '' : trim((string) $v);
    }

    // ---------------------------------------------------------------- users

    public const HR_COLUMNS = [
        'branch' => 'branch',
        'organization' => 'organization',
        'jobPosition' => 'job_position',
        'jobLevel' => 'job_level',
        'employmentStatus' => 'employment_status',
        'joinDate' => 'join_date',
    ];

    public static function userColumnsSql(): string
    {
        return 'id, name, pin, active, keterangan, no_hp, talenta_id, username, '
            .implode(', ', array_values(self::HR_COLUMNS));
    }

    /** @return array<string,mixed>|null */
    public function userById(string $id): ?array
    {
        $id = self::s($id);
        if (array_key_exists($id, $this->userCache)) {
            return $this->userCache[$id];
        }
        $r = $this->db()->selectOne('SELECT '.self::userColumnsSql().' FROM `users` WHERE id = ? LIMIT 1', [$id]);

        return $this->userCache[$id] = $r ? (array) $r : null;
    }

    public function forgetUser(string $id): void
    {
        unset($this->userCache[$id], $this->adminCache[$id], $this->builtinCache[$id]);
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
            "SELECT $cols FROM `users` WHERE LOWER(TRIM(name)) = ? AND TRIM(pin) = ? AND active = 1 LIMIT 1",
            [$u, $pin]
        );

        return $r ? (array) $r : null;
    }

    // ---------------------------------------------------------------- rules

    public static function timBawaanJadwal(): array
    {
        return ['kitchen', 'dapur', 'bar', 'bartender', 'floor', 'service', 'waiter', 'waitress',
            'host', 'hostess', 'cashier', 'kasir', 'hrd', 'hr', 'ceo'];
    }

    public static function timBolehDw(): array
    {
        return ['hrd', 'hr', 'ceo'];
    }

    public static function timAdminRoster(): array
    {
        return ['hrd', 'hr'];
    }

    public static function modulAdminBawaan(): array
    {
        return ['jadwal', 'dw'];
    }

    /** Whole-word match on the Tim column: "Barista" is not "bar". */
    public static function timCocok(?string $keterangan, array $daftar): bool
    {
        $kata = preg_split('/[^a-z]+/', strtolower((string) $keterangan), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($daftar as $x) {
            if (in_array($x, $kata, true)) {
                return true;
            }
        }

        return false;
    }

    /** Rule descriptions sent to the Kelola Akses screen (bawaan / terbatas / adminBawaan). */
    public static function aturanBawaan(): array
    {
        return [
            ['module' => 'jadwal', 'tim' => self::timBawaanJadwal(), 'adminModul' => true],
            ['module' => 'dw', 'tim' => self::timBolehDw(), 'adminModul' => true],
        ];
    }

    public static function aturanTerbatas(): array
    {
        return [['module' => 'dw', 'tim' => self::timBolehDw(), 'adminModul' => true, 'head' => true]];
    }

    public static function aturanAdminBawaan(): array
    {
        return [['modules' => self::modulAdminBawaan(), 'tim' => self::timAdminRoster()]];
    }

    // ---------------------------------------------------------------- heads

    /** @return array<string,array<int,string>> userId => [divisi…] */
    public function headMap(): array
    {
        return $this->heads->map();
    }

    public function isHead(string $userId): bool
    {
        $p = $this->headMap();

        return isset($p[$userId]) && count($p[$userId]) > 0;
    }

    /** @return array<int,string> */
    public function headDivisi(string $userId): array
    {
        $p = $this->headMap();

        return isset($p[$userId]) ? array_values($p[$userId]) : [];
    }

    // ------------------------------------------------------------ resolution

    public function activeModules(): array
    {
        if ($this->activeModules !== null) {
            return $this->activeModules;
        }
        $out = [];
        foreach ($this->db()->select('SELECT `key` FROM `modules` WHERE active = 1 ORDER BY urut ASC, `key` ASC') as $r) {
            if (self::s($r->key) !== '') {
                $out[] = self::s($r->key);
            }
        }

        return $this->activeModules = $out;
    }

    /** @return array<int,string> modules this user ADMINISTERS ('*' = superadmin). */
    public function adminModules(string $userId): array
    {
        $uid = self::s($userId);
        if (isset($this->adminCache[$uid])) {
            return $this->adminCache[$uid];
        }
        $out = [];
        foreach ($this->db()->select('SELECT `module` FROM `admins` WHERE user_id = ?', [$uid]) as $r) {
            if (self::s($r->module) !== '') {
                $out[] = self::s($r->module);
            }
        }
        if (! in_array('*', $out, true)) {
            try {
                $u = $this->userById($uid);
                if ($u && self::timCocok(self::s($u['keterangan']), self::timAdminRoster())) {
                    foreach (self::modulAdminBawaan() as $k) {
                        if (! in_array($k, $out, true)) {
                            $out[] = $k;
                        }
                    }
                }
            } catch (Throwable) {
                // legacy: stay silent, fall back to table rows only
            }
        }

        return $this->adminCache[$uid] = $out;
    }

    public function isSuperadmin(string $userId): bool
    {
        return in_array('*', $this->adminModules($userId), true);
    }

    public function isModuleAdmin(string $userId, string $module): bool
    {
        $adm = $this->adminModules($userId);

        return in_array('*', $adm, true) || in_array($module, $adm, true);
    }

    /** @return array<int,string> modules granted WITHOUT a checkbox (Tim column / admin / head). */
    public function builtinModules(string $userId): array
    {
        $uid = self::s($userId);
        if (isset($this->builtinCache[$uid])) {
            return $this->builtinCache[$uid];
        }
        $out = [];
        try {
            $u = $this->userById($uid);
            $ket = $u ? self::s($u['keterangan']) : '';
            $adm = $this->adminModules($uid);
            if (self::timCocok($ket, self::timBawaanJadwal())
                || in_array('*', $adm, true) || in_array('jadwal', $adm, true)) {
                $out[] = 'jadwal';
            }
            // isHead() asked LAST: it is the only check that costs a lookup.
            if (self::timCocok($ket, self::timBolehDw())
                || in_array('*', $adm, true) || in_array('dw', $adm, true)
                || $this->isHead($uid)) {
                $out[] = 'dw';
            }
        } catch (Throwable) {
            $out = [];
        }

        return $this->builtinCache[$uid] = $out;
    }

    private function allowedRestricted(string $userId, array $rule): bool
    {
        try {
            $u = $this->userById($userId);
            $ket = $u ? self::s($u['keterangan']) : '';
            $adm = $this->adminModules($userId);
            if (in_array('*', $adm, true)) {
                return true;
            }
            if (! empty($rule['adminModul']) && in_array($rule['module'], $adm, true)) {
                return true;
            }
            if (self::timCocok($ket, $rule['tim'])) {
                return true;
            }

            return ! empty($rule['head']) && $this->isHead($userId);
        } catch (Throwable) {
            return true; // cannot verify = do NOT revoke (legacy rule)
        }
    }

    /** @return array<int,string> modules this user may OPEN (sorted). */
    public function modules(string $userId): array
    {
        $uid = self::s($userId);
        $rows = $this->db()->select('SELECT `module`, `access` FROM `grants` WHERE user_id = ?', [$uid]);

        $eff = [];
        foreach ($this->builtinModules($uid) as $k) {          // 0) built-in
            $eff[$k] = true;
        }
        foreach ($rows as $g) {                                // 1) '*'
            if (self::s($g->module) !== '*') {
                continue;
            }
            if ((int) $g->access === 1) {
                foreach ($this->activeModules() as $k) {
                    $eff[$k] = true;
                }
            }
        }
        $explicit = [];
        foreach ($rows as $g) {                                // 2) per module
            $m = self::s($g->module);
            if ($m === '' || $m === '*') {
                continue;
            }
            if ((int) $g->access === 1) {
                $eff[$m] = true;
                $explicit[$m] = true;
            } else {
                unset($eff[$m]);
            }
        }
        foreach (self::aturanTerbatas() as $r) {               // 3) restricted
            if (! isset($eff[$r['module']]) || isset($explicit[$r['module']])) {
                continue;
            }
            if (! $this->allowedRestricted($uid, $r)) {
                unset($eff[$r['module']]);
            }
        }
        $out = array_keys($eff);
        sort($out);

        return array_values(array_map('strval', $out));
    }

    public function hasModule(string $userId, string $module): bool
    {
        $mods = $this->modules($userId);

        return in_array('*', $mods, true) || in_array($module, $mods, true);
    }

    /** Raw grant rows (for the Kelola Akses checkboxes). */
    public function rawGrants(string $userId, int $access): array
    {
        $out = [];
        foreach ($this->db()->select('SELECT `module` FROM `grants` WHERE user_id = ? AND access = ?', [$userId, $access]) as $r) {
            if (self::s($r->module) !== '') {
                $out[] = self::s($r->module);
            }
        }

        return $out;
    }

    /**
     * The identity payload every consumer needs (legacy `whoami` shape).
     *
     * @return array<string,mixed>
     */
    public function profile(array $u): array
    {
        $id = self::s($u['id']);

        return [
            'id' => $id,
            'name' => self::s($u['name']),
            'username' => self::s($u['username'] ?? ''),
            'keterangan' => self::s($u['keterangan'] ?? ''),
            'modules' => $this->modules($id),
            'adminModules' => $this->adminModules($id),
            'headDivisi' => $this->headDivisi($id),
        ];
    }
}
