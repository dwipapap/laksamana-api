<?php

namespace App\Modules\Bd\Services;

use App\Support\Modules;
use App\Support\NamedLock;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Single-record access for /api/v1/bd — the granular counterpart of the
 * legacy whole-state saveAll, under the SAME bd_save lock.
 *
 *   - a record's version is its `updated_at` column (ms), the ordering guard
 *     legacy saveAll compares against
 *   - update/delete need the version the client last saw; newer => conflict
 *   - a write stamps max(now, stored+1) into the column and data.updatedAt, so
 *     an old laksamana-office tab never overwrites it with an older copy, and
 *     its sinceTs-bounded delete never removes a row created after it loaded
 *   - settings documents (focus, approverSets, promos) are hash-versioned
 */
class BdRecords
{
    /** api resource => [app collection key, id prefix of the app's uid()] */
    public const RESOURCES = [
        'people' => ['people', 'u'],
        'projects' => ['projects', 'p'],
        'tasks' => ['tasks', 't'],
        'routines' => ['routines', 'r'],
        'coord-requests' => ['coord', 'c'],
        'purchase-orders' => ['po', 'po'],
        'purchase-requests' => ['pr', 'pr'],
        'agenda' => ['agenda', 'a'],
    ];

    /** settings key => default (same as legacy getAll) */
    public const DOCUMENTS = ['focus' => 'object', 'approverSets' => 'list', 'promos' => 'list'];

    public function __construct(private readonly BdState $state) {}

    private function db(): ConnectionInterface
    {
        return Modules::db('bd');
    }

    public static function def(string $resource): array
    {
        if (! isset(self::RESOURCES[$resource])) {
            throw new InvalidArgumentException("unknown resource $resource");
        }

        return BdState::collections()[self::RESOURCES[$resource][0]];
    }

    // ───────────────────────────── reads ──

    /** @return list<array> rows in legacy order (created_at, id) */
    public function list(string $resource, int $updatedSince = 0): array
    {
        $t = BdState::table(self::def($resource)['table']);
        $key = BdState::idCol();
        $out = [];
        foreach ($this->db()->select("SELECT `data` FROM `$t` WHERE `updated_at` > ? ORDER BY `created_at` ASC, `$key` ASC", [$updatedSince]) as $r) {
            $d = json_decode((string) $r->data, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /** @return array{row:array,version:int}|null */
    public function find(string $resource, string $id, bool $lock = false): ?array
    {
        $t = BdState::table(self::def($resource)['table']);
        $r = $this->db()->selectOne("SELECT `data`, `updated_at` FROM `$t` WHERE `".BdState::idCol().'` = ?'.($lock ? ' FOR UPDATE' : ''), [$id]);
        if (! $r) {
            return null;
        }
        $d = json_decode((string) $r->data, true);

        return ['row' => is_array($d) ? $d : ['id' => $id], 'version' => (int) $r->updated_at];
    }

    // ───────────────────────────── writes ──

    /** @return array{row:array,version:int} ; throws BdConflict('exists') */
    public function create(string $resource, array $row): array
    {
        $prefix = self::RESOURCES[$resource][1] ?? 'x';

        return $this->locked(function () use ($resource, $row, $prefix) {
            $id = isset($row['id']) && $row['id'] !== '' ? (string) $row['id'] : $prefix.self::rand7();
            if ($this->find($resource, $id, true)) {
                throw new BdConflict('exists');
            }
            $now = BdState::nowMs();
            $row = ['id' => $id] + $row;
            $row['createdAt'] = $row['createdAt'] ?? $now;
            $row['updatedAt'] = $now;
            $this->state->writeRow(self::def($resource), $row, $now, RowSync::ms($row['createdAt']), false);

            return $this->find($resource, $id);
        });
    }

    /**
     * PUT ($merge=false) replaces the record; PATCH ($merge=true) merges top-level fields.
     *
     * @return array{row:array,version:int}|null null = not found ; throws BdConflict('stale')
     */
    public function update(string $resource, string $id, array $fields, int $base, bool $merge): ?array
    {
        return $this->locked(function () use ($resource, $id, $fields, $base, $merge) {
            $cur = $this->find($resource, $id, true);
            if (! $cur) {
                return null;
            }
            if ($cur['version'] !== $base) {
                throw new BdConflict('stale', $cur);
            }
            $row = $merge ? array_replace($cur['row'], $fields) : $fields;
            $row['id'] = $id;
            $row['createdAt'] = $cur['row']['createdAt'] ?? ($row['createdAt'] ?? 0);
            $stamp = max(BdState::nowMs(), $cur['version'] + 1);
            $row['updatedAt'] = $stamp;
            $this->state->writeRow(self::def($resource), $row, $stamp, RowSync::ms($row['createdAt']), false);

            return $this->find($resource, $id);
        });
    }

    /** @return bool false = not found ; throws BdConflict('stale') */
    public function delete(string $resource, string $id, int $base): bool
    {
        return $this->locked(function () use ($resource, $id, $base) {
            $cur = $this->find($resource, $id, true);
            if (! $cur) {
                return false;
            }
            if ($cur['version'] !== $base) {
                throw new BdConflict('stale', $cur);
            }
            $this->db()->delete('DELETE FROM `'.BdState::table(self::def($resource)['table']).'` WHERE `'.BdState::idCol().'` = ?', [$id]);

            return true;
        });
    }

    // ───────────────────────────── settings documents ──

    /** @return array{value:mixed,version:string} */
    public function document(string $key): array
    {
        $raw = $this->db()->selectOne('SELECT `v` FROM `'.BdState::table('settings').'` WHERE `k` = ?', [$key])?->v;
        $value = $raw === null ? null : json_decode((string) $raw, true);
        if ($value === null) {
            $value = self::DOCUMENTS[$key] === 'object' ? new \stdClass : [];
        }

        return ['value' => $value, 'version' => substr(sha1((string) $raw), 0, 16)];
    }

    /** @return array{value:mixed,version:string} ; throws BdConflict('stale') */
    public function putDocument(string $key, mixed $value, string $base): array
    {
        return $this->locked(function () use ($key, $value, $base) {
            $cur = $this->document($key);
            if (! hash_equals($cur['version'], $base)) {
                throw new BdConflict('stale', ['value' => $cur['value'], 'version' => $cur['version']]);
            }
            $this->state->putSetting($key, $value);

            return $this->document($key);
        });
    }

    // ───────────────────────────── helpers ──

    private function locked(\Closure $fn): mixed
    {
        return NamedLock::run('bd', 'bd_save', fn () => $this->db()->transaction($fn), 10, BdState::BUSY);
    }

    /** Math.random().toString(36).slice(2,9) — 7 chars of [0-9a-z]. */
    private static function rand7(): string
    {
        $s = '';
        for ($i = 0; $i < 7; $i++) {
            $s .= '0123456789abcdefghijklmnopqrstuvwxyz'[random_int(0, 35)];
        }

        return $s;
    }
}
