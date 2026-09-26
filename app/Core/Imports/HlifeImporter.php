<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Howandi Life OS cutover import (#65): the twelve id-keyed collections and
 * the finance ledger verbatim (indexed columns + the full `data` JSON) plus
 * the settings documents into the hlife_* tables of `core`. No Office User
 * links exist in hlife, so no account import is needed first.
 * Mapping: docs/db/hlife.md.
 *
 * Idempotent: rows are matched on `legacy_id` (or `k` for hlife_pengaturan),
 * unchanged rows are not touched, changed rows get `version + 1`, and rows
 * whose legacy source is gone are deleted. Legacy hlife has no stamps, so
 * the ms tech columns import as 0.
 */
final class HlifeImporter implements Importer
{
    /** The twelve id-keyed collections plus the finance ledger. */
    private const TABLES = ['businesses', 'projects', 'tasks', 'goals', 'dreams', 'roadmap',
        'content', 'learning', 'habits', 'events', 'assets', 'reviews', 'ledger'];

    public function module(): string
    {
        return 'hlife';
    }

    public function legacyConnections(): array
    {
        return ['legacy_hlife'];
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
        $legacy = DB::connection('legacy_hlife');
        $rows = [];
        foreach (self::TABLES as $t) {
            $rows[$t] = $legacy->table($t)->orderBy('id')->get();
        }
        $settings = $legacy->table('settings')->orderBy('k')->get();

        $count = 0;
        $this->core()->transaction(function () use ($rows, $settings, &$count): void {
            foreach ($rows as $t => $list) {
                $wanted = [];
                foreach ($list as $r) {
                    $a = (array) $r;
                    $id = (string) $a['id'];
                    unset($a['id']);
                    $wanted[$id] = $a;
                }
                $this->sync('hlife_'.$t, $wanted);
                $count += count($wanted);
            }
            $wantSet = [];
            foreach ($settings as $r) {
                $wantSet[(string) $r->k] = ['v' => (string) $r->v];
            }
            $this->sync('hlife_pengaturan', $wantSet, 'k');
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
