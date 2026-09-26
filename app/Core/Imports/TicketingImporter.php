<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ticketing cutover import (#61): the public shop's five own tables into
 * ticketing_* of `core`, with the Buyer link resolved (tix_sessions.user_id and
 * tix_reset.user_id → ticketing_buyers.id, kept as buyer_id). The EMS tables
 * the shop uses belong to the event import (EventImporter). Mapping:
 * docs/db/event.md.
 *
 * Idempotent like every importer: matched on `legacy_id` (the legacy primary
 * key — `id`, or `token` for sessions/resets), version + 1 on change, rows whose
 * legacy source is gone are deleted.
 */
final class TicketingImporter implements Importer
{
    /** legacy table => core table */
    private const TABLES = [
        'tix_users' => 'ticketing_buyers',
        'tix_sessions' => 'ticketing_sessions',
        'tix_reset' => 'ticketing_resets',
        'seat_holds' => 'ticketing_seat_holds',
        'tix_gagal' => 'ticketing_gagal',
    ];

    /** legacy table => its primary key column (moved to `legacy_id`) */
    private const KEYS = [
        'tix_users' => 'id',
        'tix_sessions' => 'token',
        'tix_reset' => 'token',
        'seat_holds' => 'id',
        'tix_gagal' => 'id',
    ];

    public function module(): string
    {
        return 'ticketing';
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
        $count = 0;

        $this->core()->transaction(function () use ($legacy, &$count): void {
            // buyers first: the sessions/resets below point at them
            $this->sync('ticketing_buyers', $this->rows($legacy, 'tix_users'));
            $count += count($this->rows($legacy, 'tix_users'));

            $buyers = $this->core()->table('ticketing_buyers')->pluck('id', 'legacy_id');
            foreach (['tix_sessions' => 'ticketing_sessions', 'tix_reset' => 'ticketing_resets'] as $t => $core) {
                $wanted = $this->rows($legacy, $t);
                foreach ($wanted as $k => &$cols) {
                    $owner = $cols['user_id'] ?? null;
                    $cols['buyer_id'] = ($owner === null || $owner === '') ? null : ($buyers[$owner] ?? null);
                }
                unset($cols);
                $this->sync($core, $wanted);
                $count += count($wanted);
            }

            foreach (['seat_holds' => 'ticketing_seat_holds', 'tix_gagal' => 'ticketing_gagal'] as $t => $core) {
                $wanted = $this->rows($legacy, $t);
                $this->sync($core, $wanted);
                $count += count($wanted);
            }
        });

        return $count;
    }

    /**
     * The legacy rows keyed by their primary key, with that column removed (on
     * core it lives in `legacy_id`) and `data`-less columns kept verbatim.
     *
     * @return array<string,array<string,mixed>>
     */
    private function rows(ConnectionInterface $legacy, string $table): array
    {
        $key = self::KEYS[$table];
        $out = [];
        foreach ($legacy->table($table)->orderBy($key)->get() as $r) {
            $a = (array) $r;
            $k = (string) $a[$key];
            unset($a[$key]);
            $out[$k] = $a;
        }

        return $out;
    }

    /**
     * Upsert rows keyed by legacy_id: new ones get a fresh ULID and version 1,
     * changed ones version + 1, rows whose key left the source are deleted.
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function sync(string $table, array $rows): void
    {
        $db = $this->core();
        $existing = $db->table($table)->get()->keyBy('legacy_id');
        foreach ($rows as $k => $cols) {
            $cur = $existing[$k] ?? null;
            if ($cur === null) {
                $db->table($table)->insert(['id' => strtolower((string) Str::ulid()), 'legacy_id' => $k, ...$cols, 'version' => 1]);

                continue;
            }
            $changed = array_filter($cols, fn ($v, $c) => ! self::same($cur->$c ?? null, $v), ARRAY_FILTER_USE_BOTH);
            if ($changed !== []) {
                $db->table($table)->where('legacy_id', $k)->update([...$changed, 'version' => (int) $cur->version + 1]);
            }
        }
        $gone = array_diff(array_map('strval', $existing->keys()->all()), array_map('strval', array_keys($rows)));
        foreach (array_chunk($gone, 500) as $chunk) {
            $db->table($table)->whereIn('legacy_id', $chunk)->delete();
        }
    }

    private static function same(mixed $stored, mixed $wanted): bool
    {
        if ($stored === null || $wanted === null) {
            return $stored === $wanted;
        }

        return (string) $stored === (string) (is_bool($wanted) ? (int) $wanted : $wanted);
    }
}
