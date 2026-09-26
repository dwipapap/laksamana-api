<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reservasi cutover import (#71): the reservations verbatim (indexed columns +
 * the full `data` JSON, the source of truth), the append-only audit trail and
 * the settings documents (`master`, `_ver`) into the reservasi_* tables of
 * `core`. Mapping: docs/db/reservasi.md.
 *
 * Idempotent: rows are matched on `legacy_id` (`k` for reservasi_pengaturan),
 * unchanged rows are not touched, changed rows get `version + 1`, and rows
 * whose legacy source is gone are deleted. Photo files never live in MySQL, so
 * the import moves no bytes of them; `RESERVASI_DATA_DIR` keeps serving them.
 */
final class ReservasiImporter implements Importer
{
    /** Indexed columns of `reservations`, as in the live legacy schema. */
    private const RESERVATION_COLS = ['name', 'phone', 'tanggal', 'jam', 'pax', 'status',
        'pic_name', 'source', 'dp_amount', 'updated_at', 'created_at', 'data'];

    public function module(): string
    {
        return 'reservasi';
    }

    public function legacyConnections(): array
    {
        return ['legacy_reservasi'];
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
        $legacy = DB::connection('legacy_reservasi');
        $reservations = $legacy->table('reservations')->orderBy('id')->get();
        $audit = $legacy->table('audit')->orderBy('id')->get();
        $settings = $legacy->table('settings')->orderBy('k')->get();

        $count = 0;
        $this->core()->transaction(function () use ($reservations, $audit, $settings, &$count): void {
            $rows = [];
            foreach ($reservations as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                // the app's own guard (legacy tanggal_valid): a malformed DATE
                // becomes NULL, never a zero date a strict core would refuse
                $a['tanggal'] = self::tanggal($a['tanggal'] ?? null);
                $rows[$id] = array_intersect_key($a, array_flip(self::RESERVATION_COLS));
            }
            $this->sync('reservasi_reservations', $rows);
            $count += count($rows);

            $rows = [];
            foreach ($audit as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $rows[$id] = ['ts' => (int) $a['ts'], 'data' => (string) $a['data']];
            }
            $this->sync('reservasi_audit', $rows);
            $count += count($rows);

            $rows = [];
            foreach ($settings as $r) {
                $rows[(string) $r->k] = ['v' => (string) $r->v];
            }
            $this->sync('reservasi_pengaturan', $rows, 'k');
            $count += count($rows);
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

    /** The app's own guard (legacy tanggal_valid): 'YYYY-MM-DD' or NULL. */
    private static function tanggal(mixed $d): ?string
    {
        $d = trim((string) ($d ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d !== '0000-00-00' ? $d : null;
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
