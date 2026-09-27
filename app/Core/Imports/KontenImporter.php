<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Konten cutover import (#55): every row table verbatim (indexed columns +
 * the full `data` JSON), the append-only logs and every settings document
 * into the konten tables of `core`. Mapping: docs/db/konten.md.
 *
 * Idempotent: rows are matched on `legacy_id` (or the natural key `k` for
 * pengaturan), unchanged rows are not touched, changed rows get
 * `version + 1`, and rows whose legacy source is gone are deleted.
 */
final class KontenImporter implements Importer
{
    public function module(): string
    {
        return 'konten';
    }

    public function legacyConnections(): array
    {
        return ['legacy_konten'];
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
        $legacy = DB::connection('legacy_konten');
        $map = [
            'users' => 'konten_users',
            'brands' => 'konten_brands',
            'campaigns' => 'konten_campaigns',
            'content' => 'konten_content',
            'prod_tasks' => 'konten_prod_tasks',
            'shootings' => 'konten_shootings',
            'assets' => 'konten_assets',
            'bank' => 'konten_bank',
            'kols' => 'konten_kols',
            'visits' => 'konten_visits',
            'ads' => 'konten_ads',
            'ad_funds' => 'konten_ad_funds',
            'notifs' => 'konten_notifs',
        ];
        $rows = [];
        foreach ($map as $from => $to) {
            $rows[$to] = $legacy->table($from)->orderBy('id')->get();
        }
        $logs = $legacy->table('logs')->orderBy('id')->get();
        $settings = $legacy->table('settings')->orderBy('k')->get();

        $count = 0;
        $this->core()->transaction(function () use ($rows, $logs, $settings, &$count): void {
            foreach ($rows as $to => $list) {
                $wanted = [];
                foreach ($list as $r) {
                    $a = (array) $r;
                    $id = (string) $a['id'];
                    unset($a['id']);
                    $wanted[$id] = $a;
                }
                $this->sync($to, $wanted);
                $count += count($wanted);
            }

            $wantLogs = [];
            foreach ($logs as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $wantLogs[$id] = $a;
            }
            $this->sync('konten_logs', $wantLogs);
            $count += count($wantLogs);

            $wantSet = [];
            foreach ($settings as $r) {
                $wantSet[(string) $r->k] = ['v' => (string) $r->v];
            }
            $this->sync('konten_pengaturan', $wantSet, 'k');
            $count += count($wantSet);
        });

        return $count;
    }

    /**
     * Upsert rows keyed by $key: insert new ones with a fresh ULID and
     * version 1, update only changed ones (version + 1), delete rows whose
     * key is no longer in the source. The `data`/`v` payloads compare
     * decoded, so re-encoding drift never counts as a change.
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function sync(string $table, array $rows, string $key = 'legacy_id'): void
    {
        $db = $this->core();
        $existing = $db->table($table)->whereNotNull($key)->get()->keyBy($key);
        foreach ($rows as $k => $cols) {
            $cur = $existing[$k] ?? null;
            if ($cur === null) {
                $db->table($table)->insert(['id' => strtolower((string) Str::ulid()), $key => $k, ...$cols, 'version' => 1]);

                continue;
            }
            $changed = [];
            foreach ($cols as $c => $v) {
                if (self::same($cur->$c ?? null, $v)) {
                    continue;
                }
                $changed[$c] = $v;
            }
            if ($changed === []) {
                continue;
            }
            $changed['version'] = (int) $cur->version + 1;
            $db->table($table)->where($key, $k)->update($changed);
        }
        $gone = $db->table($table)->whereNotNull($key)->pluck($key)
            ->reject(fn ($k) => in_array((string) $k, array_map('strval', array_keys($rows)), true));
        foreach ($gone->chunk(500) as $chunk) {
            $db->table($table)->whereIn($key, $chunk->all())->delete();
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
        if ($stored === null || is_bool($stored)) {
            return self::norm($stored) === self::norm($wanted);
        }

        return (string) $stored === (string) $wanted;
    }

    private static function norm(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return (string) $v;
    }
}
