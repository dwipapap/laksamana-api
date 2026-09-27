<?php

namespace App\Modules\Account\Services;

use App\Auth\AccountRepository;
use App\Auth\CoreAccountRepository;
use App\Auth\LegacySessions;
use App\Auth\OfficeAccess;
use App\Support\Modules;

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
        private readonly AccountRepository $users,
    ) {}

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
        return $this->users->allUsers();
    }

    public function identityClash(string $cand, string $exceptId): ?array
    {
        return $this->users->identityClash($cand, $exceptId);
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
        $n = $this->users->updatePinByName($name, $next);
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
            $this->users->updateUsername($userId, '');

            return ['ok' => true, 'username' => ''];
        }
        if (! self::usernameValid($new)) {
            return ['ok' => false, 'error' => 'bad_username'];
        }
        if ($this->identityClash($new, $userId)) {
            return ['ok' => false, 'error' => 'username_taken'];
        }
        $this->users->updateUsername($userId, $new);
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
            $d = $this->users->talentaOwner($tid, $editId);
            if ($d) {
                return ['ok' => false, 'error' => 'talenta_taken', 'takenBy' => self::s($d['name'])];
            }
        }

        if ($editId !== '') {
            $columns = ['name' => $name, 'pin' => $pin, 'active' => $active,
                'keterangan' => $ket, 'no_hp' => $hp, 'talenta_id' => $tid];
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
                $columns['username'] = $un;
            }
            foreach (OfficeAccess::HR_COLUMNS as $key => $col) {
                if (! array_key_exists($key, $body)) {
                    continue;
                }
                $columns[$col] = self::hrValue($key, $body[$key]);
            }
            $n = $this->users->updateUser($editId, $columns);
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
        $columns = ['id' => $id, 'name' => $name, 'pin' => $pin, 'active' => $active,
            'keterangan' => $ket, 'no_hp' => $hp, 'talenta_id' => $tid];
        foreach (OfficeAccess::HR_COLUMNS as $key => $col) {
            $columns[$col] = array_key_exists($key, $body) ? self::hrValue($key, $body[$key]) : '';
        }
        $this->users->insertUser($columns);

        return ['ok' => true, 'id' => $id];
    }

    public function saveUsers(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->saveUsersCore(is_array($body['users'] ?? null) ? $body['users'] : []);
    }

    /** Bulk saveUser WITHOUT the callerName+callerPin gate (v1 route already holds it). */
    public function saveUsersCore(array $rows): array
    {
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
            $h = $this->saveUserCore($r);
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
        // On core the kepala_divisi FK refuses the delete (#2); say so instead of failing.
        if ($this->users instanceof CoreAccountRepository && ($div = $this->users->kepalaDivisiOf($id))) {
            return ['ok' => false, 'error' => 'is_kepala_divisi', 'divisi' => $div];
        }
        $this->users->deleteUserCascade($id);
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
        $this->users->setActive($id, $active);
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
        return $this->users->moduleRows($all);
    }

    /** Adds NEW keys only; never overwrites label/active, never deletes. */
    public function syncModules(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->syncModulesCore(is_array($body['modules'] ?? null) ? $body['modules'] : []);
    }

    /** syncModules WITHOUT the callerName+callerPin gate (v1 route already holds it). */
    public function syncModulesCore(array $incoming): array
    {
        $urut = $this->users->maxModuleUrut();
        $added = [];
        foreach ($incoming as $m) {
            $m = (array) $m;
            $key = self::s($m['key'] ?? '');
            if ($key === '' || $this->users->moduleExists($key)) {
                continue;
            }
            $label = self::s($m['label'] ?? '') ?: $key;
            $urut += 10;
            $this->users->insertModule($key, $label, $urut);
            $added[] = $key;
        }

        return ['ok' => true, 'added' => $added];
    }

    public function saveModule(array $body): array
    {
        if (! $this->superadmin($body)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        return $this->saveModuleCore(self::s($body['key'] ?? ''),
            isset($body['label']) ? $body['label'] : null,
            isset($body['active']) ? $body['active'] : null);
    }

    /** saveModule WITHOUT the callerName+callerPin gate. null = field not sent (legacy `isset`). */
    public function saveModuleCore(string $key, mixed $label, mixed $active): array
    {
        if ($key === '') {
            return ['ok' => false, 'error' => 'missing_key'];
        }
        if (! $this->users->moduleExists($key)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($label !== null) {
            $this->users->updateModuleLabel($key, self::s($label));
        }
        if ($active !== null) {
            $this->users->updateModuleActive($key, self::truthy($active));
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
            if ($this->users->countSuperadmins() <= 1) {
                return ['ok' => false, 'error' => 'last_superadmin'];
            }
        }
        if ($grant) {
            $this->users->grantAdmin($userId, $module);
        } else {
            $this->users->revokeAdmin($userId, $module);
        }
        $this->access->forgetUser($userId);

        return ['ok' => true];
    }

    /**
     * setAdmin WITHOUT the callerName+callerPin gate (the v1 caller is the
     * Sanctum user, already authorised by the route). Same guard, same write.
     */
    public function setAdminCore(string $callerId, string $userId, string $module, bool $grant): array
    {
        if ($userId === '' || $module === '') {
            return ['ok' => false, 'error' => 'missing_fields'];
        }
        if ($module === '*' && ! $grant && $callerId === $userId) {
            if ($this->users->countSuperadmins() <= 1) {
                return ['ok' => false, 'error' => 'last_superadmin'];
            }
        }
        if ($grant) {
            $this->users->grantAdmin($userId, $module);
        } else {
            $this->users->revokeAdmin($userId, $module);
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
        $this->users->upsertGrant($userId, $module, $access, $callerId);
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

        return $this->importCore($body);
    }

    /** import WITHOUT the callerName+callerPin gate. Idempotent, upserts users/modules/grants. */
    public function importCore(array $body): array
    {
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
        foreach (is_array($body['users'] ?? null) ? $body['users'] : [] as $u) {
            $u = (array) $u;
            $id = self::s($u['id'] ?? '');
            $nm = self::s($u['name'] ?? '');
            if ($id === '' || $nm === '') {
                continue;
            }
            $this->users->upsertImportUser($id, $nm,
                self::s($u['pin'] ?? '') !== '' ? self::s($u['pin']) : '1111',
                $bool($u['active'] ?? null), self::s($u['keterangan'] ?? ''), self::s($u['talentaId'] ?? ''));
            $n['users']++;
        }
        foreach (is_array($body['modules'] ?? null) ? $body['modules'] : [] as $m) {
            $m = (array) $m;
            $k = self::s($m['key'] ?? '');
            if ($k === '') {
                continue;
            }
            $this->users->upsertImportModule($k,
                self::s($m['label'] ?? '') !== '' ? self::s($m['label']) : $k, $bool($m['active'] ?? null));
            $n['modules']++;
        }
        foreach (is_array($body['grants'] ?? null) ? $body['grants'] : [] as $g) {
            $g = (array) $g;
            $u = self::s($g['userId'] ?? '');
            $m = self::s($g['module'] ?? '');
            if ($u === '' || $m === '') {
                continue;
            }
            $this->users->upsertImportGrant($u, $m, $bool($g['access'] ?? null), self::s($g['grantedBy'] ?? 'import'));
            $n['grants']++;
        }
        foreach (is_array($body['admins'] ?? null) ? $body['admins'] : [] as $a) {
            $a = (array) $a;
            $u = self::s($a['userId'] ?? '');
            $m = self::s($a['module'] ?? '');
            if ($u === '' || $m === '') {
                continue;
            }
            $this->users->grantAdmin($u, $m);
            $n['admins']++;
        }

        return ['ok' => true, 'diproses' => $n];
    }

    /** Seed three superadmins ONLY when `users` is completely empty (fresh install). */
    public function seedIfEmpty(): bool
    {
        if ($this->users->userCount() > 0) {
            return false;
        }
        foreach ([['u-admin', 'Admin'], ['u-howandi', 'Howandi'], ['u-wandi', 'Wandi']] as [$id, $name]) {
            $this->users->insertIgnoreUser($id, $name);
            $this->users->insertIgnoreGrant($id, '*', 1, 'seed');
            $this->users->grantAdmin($id, '*');
        }
        $this->users->insertIgnoreGrant('u-wandi', 'howandi_life', 0, 'seed');

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
            $out[$t] = $this->users->countTable($t);
        }
        $out['superadmin'] = $this->users->countSuperadmins();
        $out['ts'] = gmdate('c');

        return $out;
    }
}
