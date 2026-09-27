<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BD OS cutover import (#59): the eight row tables verbatim (indexed columns +
 * the full `data` JSON) and the settings documents into the bd_* tables of
 * `core`. people.office_user_id is also resolved to its core User (`user_id`),
 * so run the account import first. Mapping: docs/db/bd.md.
 *
 * Idempotent: rows are matched on `legacy_id` (or `k` for bd_pengaturan),
 * unchanged rows are not touched, changed rows get `version + 1`, and rows
 * whose legacy source is gone are deleted.
 */
final class BdImporter implements Importer
{
    private const TABLES = ['people', 'projects', 'tasks', 'routines', 'coord_requests',
        'purchase_orders', 'purchase_requests', 'agenda'];

    public function module(): string
    {
        return 'bd';
    }

    public function legacyConnections(): array
    {
        return ['legacy_bd'];
    }

    public function targetConnection(): string
    {
        return 'core';
    }

    private function core(): ConnectionInterface
    {
        return DB::connection($this->targetConnection());
    }

    public function import(): int
    {
        $legacy = DB::connection('legacy_bd');
        $rows = [];
        foreach (self::TABLES as $t) {
            $rows[$t] = $legacy->table($t)->orderBy('id')->get();
        }
        $settings = $legacy->table('settings')->orderBy('k')->get();
        $users = $this->core()->table('user')->pluck('id', 'legacy_id');

        $count = 0;
        $this->core()->transaction(function () use ($rows, $settings, $users, &$count): void {
            foreach ($rows as $t => $list) {
                $wanted = [];
                foreach ($list as $r) {
                    $a = (array) $r;
                    $id = (string) $a['id'];
                    unset($a['id']);
                    if ($t === 'people') {
                        $a['user_id'] = $a['office_user_id'] === null ? null : ($users[$a['office_user_id']] ?? null);
                    }
                    $wanted[$id] = $a;
                }
                $this->sync('bd_'.$t, $wanted);
                $count += count($wanted);
            }
            $wantSet = [];
            foreach ($settings as $r) {
                $wantSet[(string) $r->k] = ['v' => (string) $r->v];
            }
            $this->sync('bd_pengaturan', $wantSet, 'k');
            $count += count($wantSet);
        });

        return $count;
    }

    /**
     * Upsert rows keyed by $key: new ones get a fresh ULID and version 1,
     * changed ones version + 1, rows whose key left the source are deleted.
     * `data`/`v` compare decoded, so re-encoding drift never counts as a change.
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function sync(string $table, array $rows, string $key = 'legacy_id'): void
    {
        $db = $this->core();
        $existing = $db->table($table)->get()->keyBy($key);
        foreach ($rows as $k => $cols) {
            $cur = $existing[$k] ?? null;
            if ($cur === null) {
                $db->table($table)->insert(['id' => strtolower((string) Str::ulid()), $key => $k, ...$cols, 'version' => 1]);

                continue;
            }
            $changed = array_filter($cols, fn ($v, $c) => ! self::same($cur->$c ?? null, $v), ARRAY_FILTER_USE_BOTH);
            if ($changed !== []) {
                $db->table($table)->where($key, $k)->update([...$changed, 'version' => (int) $cur->version + 1]);
            }
        }
        $gone = array_diff(array_map('strval', $existing->keys()->all()), array_map('strval', array_keys($rows)));
        foreach (array_chunk($gone, 500) as $chunk) {
            $db->table($table)->whereIn($key, $chunk)->delete();
        }
    }

    private static function same(mixed $stored, mixed $wanted): bool
    {
        if (is_string($wanted) && ($wanted === '' || str_starts_with($wanted, '{') || str_starts_with($wanted, '['))) {
            $a = is_string($stored) ? json_decode($stored, true) : null;
            $b = json_decode($wanted, true);
            if (is_array($a) || is_array($b)) {
                return $a == $b;
            }
        }
        if ($stored === null || $wanted === null) {
            return $stored === $wanted;
        }

        return (string) $stored === (string) (is_bool($wanted) ? (int) $wanted : $wanted);
    }
}
