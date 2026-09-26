<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finance cutover import (#67): Kas Kecil, Brankas, the invoice/kwitansi queue
 * and their page/role maps, verbatim (the legacy ids stay the row identity) into
 * the finance_* tables of `core`. Mapping: docs/db/finance.md.
 *
 * Two things this import knows that a plain copy does not:
 *
 * - kk_peran/bk_peran are keyed by '#<Office User id>', so `user_id` is
 *   resolved to that User in core (the account import must run first, and
 *   `--list` is alphabetical).
 * - the int-keyed tables keep handing out INTEGERS on the compat surface (the
 *   old screens build inline handlers from them), so finance_counter is seeded
 *   with the legacy AUTO_INCREMENT: a row created on core continues the legacy
 *   numbering instead of colliding with ids the legacy backend never used.
 *
 * Idempotent: rows are matched on `legacy_id` (or `kunci`/`k`), unchanged rows
 * are not touched, changed rows get `version + 1`, and rows whose legacy source
 * is gone are deleted. The counter only ever moves forward.
 */
final class FinanceImporter implements Importer
{
    /** Sync order matters: FK targets first, then the rows that point at them. */
    private const TABLES = ['kk_kategori', 'kk_pos', 'kk_trx', 'kk_trx_pos', 'kk_akses', 'kk_peran',
        'bk_akses', 'bk_peran', 'bk_state', 'inv_kwitansi', 'inv_penanda', 'inv_setting'];

    /** Tables whose legacy id is an AUTO_INCREMENT int and is minted on core. */
    private const COUNTERS = ['kk_pos', 'kk_kategori', 'kk_trx', 'kk_trx_pos', 'kk_akses', 'bk_akses'];

    /** The natural key of the two k/v style tables (they hold no legacy id). */
    private const KEYS = ['kk_peran' => 'kunci', 'bk_peran' => 'kunci', 'inv_setting' => 'k'];

    public function module(): string
    {
        return 'finance';
    }

    public function legacyConnections(): array
    {
        return ['legacy_finance'];
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
        $legacy = DB::connection('legacy_finance');
        $rows = [];
        foreach (self::TABLES as $t) {
            $rows[$t] = $legacy->table($t)->orderBy(self::KEYS[$t] ?? 'id')->get();
        }
        $counters = $this->legacyCounters($legacy);
        $users = $this->core()->table('user')->pluck('id', 'legacy_id');

        $count = 0;
        $this->core()->transaction(function () use ($rows, $counters, $users, &$count): void {
            foreach ($rows as $t => $list) {
                $key = self::KEYS[$t] ?? 'legacy_id';
                $wanted = [];
                foreach ($list as $r) {
                    $a = $this->payload($t, (array) $r, $key, $users);
                    $wanted[(string) $a[$key]] = array_diff_key($a, [$key => 1]);
                }
                $this->sync('finance_'.$t, $wanted, $key);
                $count += count($wanted);
            }
            foreach ($counters as $name => $next) {
                // never move a counter backwards on a re-import: next_value is the
                // id the NEXT row gets, which is exactly what AUTO_INCREMENT means
                $this->core()->statement(
                    'INSERT INTO `finance_counter` (`name`, `next_value`) VALUES (?, ?) '
                    .'ON DUPLICATE KEY UPDATE `next_value` = GREATEST(`next_value`, VALUES(`next_value`))',
                    [$name, $next]
                );
            }
        });

        return $count;
    }

    /**
     * One legacy row as its core columns. `id` becomes `legacy_id`, the stamps
     * come from the legacy stamp the table has, and the two kv tables keep their
     * natural key. bk_state's free-text `updated_by` becomes `diubah_oleh`
     * (ADR-0003 reserves updated_by for the ULID actor column).
     *
     * @param  Collection<string,string>  $users  core user id by legacy id
     * @return array<string,mixed>
     */
    private function payload(string $table, array $r, string $key, $users): array
    {
        if ($key === 'legacy_id') {
            $r['legacy_id'] = (string) $r['id'];
            unset($r['id']);
            $r['updated_at'] = (int) ($r['updated_at'] ?? 0);
            $r['created_at'] = (int) ($r['created_at'] ?? 0);
        }
        if ($table === 'bk_state') {
            $r['created_at'] = (int) $r['updated_at'];
            $r['diubah_oleh'] = (string) ($r['updated_by'] ?? '');
            unset($r['updated_by']);
        }
        if ($table === 'kk_trx') {
            // the legacy creation stamp is the row's only stamp
            $r['created_at'] = $r['updated_at'] = (int) $r['dibuat_at'];
        }
        if ($table === 'kk_peran' || $table === 'bk_peran') {
            // '#u-cindy' => the Office User with legacy id 'u-cindy'
            $kunci = ltrim((string) $r['kunci'], '#');
            $r['user_id'] = $kunci === '' ? null : ($users[$kunci] ?? null);
        }

        return $r;
    }

    /**
     * The legacy AUTO_INCREMENT per int-keyed table: the id the legacy backend
     * would hand out next. Read from SHOW CREATE TABLE — the value the server
     * will really use (and what a mysqldump clone restores). information_schema
     * and SHOW TABLE STATUS report a cached stat that can lag behind it.
     */
    private function legacyCounters(ConnectionInterface $legacy): array
    {
        $out = [];
        foreach (self::COUNTERS as $t) {
            $ddl = (array) $legacy->selectOne('SHOW CREATE TABLE `'.$t.'`');
            $sql = (string) ($ddl['Create Table'] ?? '');
            if ($sql === '' && $ddl !== []) {
                $sql = (string) reset($ddl);
            }
            $out[$t] = preg_match('/AUTO_INCREMENT=(\d+)/', $sql, $m) ? max(1, (int) $m[1]) : 1;
        }

        return $out;
    }

    /**
     * Upsert rows keyed by $key: insert new ones with a fresh ULID and version 1,
     * update only the changed columns (version + 1), delete rows whose key left
     * the source. The JSON-ish payloads compare decoded, so re-encoding drift
     * never counts as a change.
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function sync(string $table, array $rows, string $key = 'legacy_id'): void
    {
        $db = $this->core();
        $existing = $db->table($table)->get()->keyBy($key);
        foreach ($rows as $k => $cols) {
            $k = (string) $k;
            $cur = $existing[$k] ?? null;
            if ($cur === null) {
                $db->table($table)->insert(['id' => strtolower((string) Str::ulid()), $key => $k, ...$cols, 'version' => 1]);

                continue;
            }
            $changed = [];
            foreach ($cols as $c => $v) {
                if (! self::same($cur->$c ?? null, $v)) {
                    $changed[$c] = $v;
                }
            }
            if ($changed !== []) {
                $changed['version'] = (int) $cur->version + 1;
                $db->table($table)->where($key, $k)->update($changed);
            }
        }
        $wanted = array_map('strval', array_keys($rows));
        $gone = $existing->keys()->map(fn ($k) => (string) $k)->diff($wanted);
        foreach ($gone->chunk(500) as $chunk) {
            $db->table($table)->whereIn($key, $chunk->values()->all())->delete();
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
