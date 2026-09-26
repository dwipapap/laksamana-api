<?php

declare(strict_types=1);

namespace App\Core\Imports;

use App\Modules\Stock\Services\StockSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock cutover import (#73): every legacy table of lakk5493_db_stock into
 * its stock_* table of `core`, columns verbatim (StockSupport::COLUMNS), the
 * legacy `id` into `legacy_id`. Mapping: docs/db/stock.md.
 *
 * Idempotent: rows match on their key (`legacy_id`, or the natural key),
 * unchanged rows are not touched, changed rows are updated, rows gone from
 * legacy are deleted (first, so a case-only rename of a unique `nama` never
 * collides). New rows get monotonic ULIDs in legacy key order, so an unordered
 * scan of core returns them in the order legacy's primary key did.
 */
final class StockImporter implements Importer
{
    /** legacy table => its key columns in core (legacy `id` becomes `legacy_id`) */
    private const KEYS = [
        'activity_log' => ['legacy_id'], 'ck_stock' => ['legacy_id'], 'hpp_bahan' => ['nama'],
        'hpp_bulan' => ['bulan'], 'hpp_pakai' => ['bulan', 'bahan'], 'hpp_resep' => ['legacy_id'],
        'hpp_setting' => ['legacy_id'], 'opname' => ['legacy_id'], 'ordering_users' => ['legacy_id'],
        'orders' => ['nomor_order'], 'products' => ['nama'], 'serah_terima' => ['legacy_id'],
        'stock' => ['nama'], 'usage_events' => ['legacy_id'], 'users' => ['legacy_id'],
        'vendors' => ['nama'], 'waste' => ['legacy_id'],
    ];

    public function module(): string
    {
        return 'stock';
    }

    public function legacyConnections(): array
    {
        return ['legacy_stock'];
    }

    public function targetConnection(): string
    {
        return 'core';
    }

    public function import(): int
    {
        $legacy = DB::connection('legacy_stock');
        $core = DB::connection($this->targetConnection());
        $count = 0;

        $core->transaction(function () use ($legacy, $core, &$count): void {
            foreach (self::KEYS as $table => $keys) {
                $target = StockSupport::CORE_TABLES[$table];
                $srcKeys = array_map(fn ($k) => $k === 'legacy_id' ? 'id' : $k, $keys);

                $rows = [];
                foreach ($legacy->table($table)->orderBy($srcKeys[0])->orderBy(end($srcKeys))->get() as $r) {
                    $row = [];
                    foreach (StockSupport::COLUMNS[$table] as $c) {
                        $row[$c === 'id' ? 'legacy_id' : $c] = $r->$c;
                    }
                    $rows[self::key($row, $keys)] = $row;
                }

                $existing = [];
                foreach ($core->table($target)->get() as $r) {
                    $existing[self::key((array) $r, $keys)] = (array) $r;
                }

                foreach (array_diff_key($existing, $rows) as $gone) {
                    $core->table($target)->where('id', $gone['id'])->delete();
                }
                $new = [];
                foreach ($rows as $k => $row) {
                    $cur = $existing[$k] ?? null;
                    if ($cur === null) {
                        $new[] = ['id' => strtolower((string) Str::ulid()), ...$row];

                        continue;
                    }
                    $changed = array_filter($row, fn ($v, $c) => ! self::same($cur[$c], $v), ARRAY_FILTER_USE_BOTH);
                    if ($changed) {
                        $core->table($target)->where('id', $cur['id'])->update($changed);
                    }
                }
                foreach (array_chunk($new, 100) as $chunk) {
                    $core->table($target)->insert($chunk);
                }
                $count += count($rows);
            }
        });

        return $count;
    }

    private static function key(array $row, array $keys): string
    {
        return implode("\0", array_map(fn ($k) => (string) $row[$k], $keys));
    }

    private static function same(mixed $stored, mixed $wanted): bool
    {
        return $stored === null || $wanted === null ? $stored === $wanted : (string) $stored === (string) $wanted;
    }
}
