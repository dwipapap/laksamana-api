<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EMS cutover import (#61): the event module's fourteen row tables (the legacy
 * indexed columns verbatim + the full `data` JSON) and its settings documents
 * into the event_* tables of `core`. Ticketing's EMS access goes through
 * EventState, so the shop reads exactly these rows. Mapping: docs/db/event.md.
 *
 * Idempotent: rows are matched on `legacy_id` (`event_id` for event_details,
 * `k` for event_pengaturan), unchanged rows are not touched, changed rows get
 * `version + 1`, and rows whose legacy source is gone are deleted.
 */
final class EventImporter implements Importer
{
    private const TABLES = ['calendar_extra', 'checkins', 'event_details', 'events', 'ideas',
        'orders', 'recurring_rules', 'refunds', 'schedules', 'seats', 'talent_payments',
        'talents', 'ticket_classes', 'tickets'];

    public function module(): string
    {
        return 'event';
    }

    public function legacyConnections(): array
    {
        return ['legacy_ems'];
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
        $legacy = DB::connection('legacy_ems');
        $rows = [];
        foreach (self::TABLES as $t) {
            $rows[$t] = $legacy->table($t)->get();
        }
        $settings = $legacy->table('settings')->orderBy('k')->get();

        $count = 0;
        $this->core()->transaction(function () use ($rows, $settings, &$count): void {
            foreach ($rows as $t => $list) {
                // event_details keeps its natural key (event_id); every other table
                // matches on the legacy id moved into `legacy_id` on core.
                $key = $t === 'event_details' ? 'event_id' : 'legacy_id';
                $legacyKey = $t === 'event_details' ? 'event_id' : 'id';
                $wanted = [];
                foreach ($list as $r) {
                    $a = (array) $r;
                    $k = (string) $a[$legacyKey];
                    if ($legacyKey === 'id') {
                        unset($a['id']);
                    }
                    if ($t === 'event_details') {
                        // the natural key doubles as the legacy id (the template's unique key)
                        $a['legacy_id'] = $k;
                    }
                    $wanted[$k] = $a;
                }
                $this->sync('event_'.$t, $wanted, $key);
                $count += count($wanted);
            }
            $wantSet = [];
            foreach ($settings as $r) {
                $wantSet[(string) $r->k] = ['v' => (string) $r->v];
            }
            $this->sync('event_pengaturan', $wantSet, 'k');
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
