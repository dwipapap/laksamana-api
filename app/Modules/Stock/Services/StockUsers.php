<?php

namespace App\Modules\Stock\Services;

use stdClass;

/**
 * The two stock crew tables of the legacy backend.
 *
 * `users` belongs to Purchasing, `ordering_users` to Ordering. Both keep a
 * plain `pin` column and the same JSON in `data`; the compat routes reproduce
 * that shape exactly (including the PIN, see docs/security-followups.md #8).
 * The v1 surface returns the public fields only and versions each row by the
 * content of its `data` JSON, like every other stock resource.
 */
class StockUsers
{
    public const TABLES = ['users', 'ordering_users'];

    public static function table(string $kind): string
    {
        $table = $kind === 'ordering' ? 'ordering_users' : 'users';

        return in_array($table, self::TABLES, true) ? $table : 'users';
    }

    /** compat: the complete list, PIN included, as objects ordered by name. */
    public function all(string $table): array
    {
        $out = [];
        foreach (StockSupport::db()->select(StockSupport::q("SELECT {id} AS `id`,`nama`,`pin`,`role`,`keterangan` FROM {{$table}} ORDER BY `nama`")) as $r) {
            $out[] = (object) [
                'id' => (string) $r->id, 'name' => (string) $r->nama, 'pin' => (string) $r->pin,
                'role' => (string) $r->role, 'keterangan' => (string) $r->keterangan,
            ];
        }

        return $out;
    }

    /** @return array{record:array,pin:string,version:string}|null */
    public function find(string $table, string $id, bool $lock = false): ?array
    {
        $r = StockSupport::db()->selectOne(StockSupport::q("SELECT {*{$table}} FROM {{$table}} WHERE {id} = ?").($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $r) {
            return null;
        }

        return $this->fromRow($r);
    }

    /** @return list<array{record:array, version:string}> */
    public function list(string $table): array
    {
        $out = [];
        foreach (StockSupport::db()->select(StockSupport::q("SELECT {*{$table}} FROM {{$table}} ORDER BY `nama`")) as $r) {
            $one = $this->fromRow($r);
            $out[] = ['record' => $one['record'], 'version' => $one['version']];
        }

        return $out;
    }

    /** compat users.php add/update — an id is required and every sent field replaces the old one. */
    public function saveCompat(string $table, mixed $user): array
    {
        if (! $user instanceof stdClass || ! isset($user->id) || StockSupport::str($user->id) === '') {
            return ['status' => 'error', 'message' => 'user tanpa id'];
        }
        $this->write($table, $this->normalise($user));

        return ['status' => 'success'];
    }

    public function deleteCompat(string $table, mixed $user): array
    {
        $id = $user instanceof stdClass ? StockSupport::str($user->id ?? '') : StockSupport::str($user);
        if ($id === '') {
            return ['status' => 'error', 'message' => 'user tanpa id'];
        }
        if ($this->lastAdmin($table, $id)) {
            return ['status' => 'error', 'message' => 'tidak bisa menghapus admin terakhir'];
        }

        return ['status' => 'success', 'deleted' => StockSupport::db()->delete(StockSupport::q("DELETE FROM {{$table}} WHERE {id} = ?"), [$id])];
    }

    /** compat ordering-users.php saveUser; a missing id is generated from pin+name+time. */
    public function saveOrdering(mixed $user): array
    {
        if (! $user instanceof stdClass) {
            return ['status' => 'error', 'message' => 'user tidak sah'];
        }
        $this->write('ordering_users', $this->normalise($user, true));

        return ['status' => 'success'];
    }

    /** compat bulkSeed: upsert by id in one transaction; nothing is deleted. */
    public function seedOrdering(mixed $users): array
    {
        if (! is_array($users)) {
            return ['status' => 'error', 'message' => 'users bukan array'];
        }

        return StockSupport::db()->transaction(function () use ($users) {
            $n = 0;
            foreach ($users as $user) {
                if ($user instanceof stdClass) {
                    $this->write('ordering_users', $this->normalise($user, true));
                    $n++;
                }
            }

            return ['status' => 'success', 'seeded' => $n];
        });
    }

    /** v1 create/update. The public record never carries the PIN back to the caller. */
    public function saveV1(string $table, ?string $id, array $fields, ?string $version): array
    {
        $db = StockSupport::db();

        return $db->transaction(function () use ($table, $id, $fields, $version) {
            $cur = $id === null ? null : $this->find($table, $id, true);
            if ($id === null && $cur !== null) {
                throw new StockConflict('exists');
            }
            if ($id !== null && $cur === null) {
                throw new StockConflict('not_found');
            }
            if ($cur !== null && ! hash_equals($cur['version'], (string) $version)) {
                throw new StockConflict('stale', $cur['record']);
            }
            $target = $id ?? trim(StockSupport::str($fields['id'] ?? ''));
            if ($target === '') {
                throw new StockConflict('invalid', 'id wajib diisi');
            }
            $base = $cur['record'] ?? [];
            $user = (object) [
                'id' => $target,
                'name' => array_key_exists('name', $fields) ? StockSupport::str($fields['name']) : ($base['name'] ?? ''),
                'pin' => array_key_exists('pin', $fields) ? StockSupport::str($fields['pin']) : ($cur['pin'] ?? ''),
                'role' => array_key_exists('role', $fields) ? StockSupport::str($fields['role']) : ($base['role'] ?? 'full'),
                'keterangan' => array_key_exists('keterangan', $fields) ? StockSupport::str($fields['keterangan']) : ($base['keterangan'] ?? ''),
            ];
            if ($user->role === '') {
                $user->role = 'full';
            }
            $this->write($table, $user);

            return $this->find($table, $target);
        });
    }

    public function deleteV1(string $table, string $id, string $version): void
    {
        StockSupport::db()->transaction(function () use ($table, $id, $version) {
            $cur = $this->find($table, $id, true);
            if (! $cur) {
                throw new StockConflict('not_found');
            }
            if (! hash_equals($cur['version'], $version)) {
                throw new StockConflict('stale', $cur['record']);
            }
            if ($this->lastAdmin($table, $id)) {
                throw new StockConflict('last_admin');
            }
            StockSupport::db()->delete(StockSupport::q("DELETE FROM {{$table}} WHERE {id} = ?"), [$id]);
        });
    }

    private function normalise(stdClass $user, bool $generateId = false): stdClass
    {
        $id = StockSupport::str($user->id ?? '');
        if ($id === '' && $generateId) {
            $id = 'u-'.substr(sha1(StockSupport::str($user->pin ?? '').StockSupport::str($user->name ?? '').microtime()), 0, 12);
        }

        return (object) [
            'id' => $id,
            'name' => StockSupport::str($user->name ?? ''),
            'pin' => StockSupport::str($user->pin ?? ''),
            'role' => StockSupport::str($user->role ?? 'full'),
            'keterangan' => StockSupport::str($user->keterangan ?? $user->tim ?? ''),
        ];
    }

    private function write(string $table, stdClass $user): void
    {
        StockSupport::db()->insert(StockSupport::q("INSERT INTO {{$table}} ({id},`nama`,`pin`,`role`,`keterangan`,`data`)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`), `pin`=VALUES(`pin`),
            `role`=VALUES(`role`), `keterangan`=VALUES(`keterangan`), `data`=VALUES(`data`)"), [
            $user->id, $user->name, $user->pin, $user->role, $user->keterangan, StockSupport::enc($user),
        ]);
    }

    private function lastAdmin(string $table, string $id): bool
    {
        if ($table !== 'users') {
            return false;
        }
        $role = StockSupport::db()->selectOne(StockSupport::q('SELECT `role` FROM {users} WHERE {id} = ?'), [$id])?->role;
        $count = (int) StockSupport::db()->selectOne(StockSupport::q("SELECT COUNT(*) c FROM {users} WHERE `role`='admin'"))->c;

        return $role === 'admin' && $count <= 1;
    }

    private function fromRow(object $r): array
    {
        return [
            'record' => ['id' => (string) $r->id, 'name' => (string) $r->nama, 'role' => (string) $r->role, 'keterangan' => (string) $r->keterangan],
            'pin' => (string) $r->pin,
            'version' => StockRecords::hash((string) $r->data),
        ];
    }
}
