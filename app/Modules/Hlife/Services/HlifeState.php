<?php

namespace App\Modules\Hlife\Services;

use App\Support\JsonDoc;
use App\Support\Modules;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use stdClass;

/**
 * Howandi Life OS — port of howandi-life-mysql/lib_hlife_mysql.php.
 *
 * The state S of the frontend: twelve id-keyed collections (one table each,
 * a few indexed columns + the full record in `data`), `finance.ledger`
 * (table `ledger`), and loose settings in `settings` (k => JSON v).
 *
 * JSON is always decoded WITHOUT the assoc flag so `{}` stays an object
 * (see JsonDoc). No conflict guard on saveAll: last save wins, as legacy.
 *
 * Cut over (#65): behind DB_HLIFE_CONNECTION=core the Modul reads and writes
 * the hlife_* tables of `core` — the legacy id lives in `legacy_id` and every
 * accepted write bumps `version`. Unset = the legacy database (rollback).
 */
class HlifeState
{
    /** App collection => table. */
    public const COLLECTIONS = [
        'businesses' => 'businesses',
        'projects' => 'projects',
        'tasks' => 'tasks',
        'goals' => 'goals',
        'dreams' => 'dreams',
        'roadmap' => 'roadmap',
        'content' => 'content',
        'learning' => 'learning',
        'habits' => 'habits',
        'events' => 'events',
        'assets' => 'assets',
        'reviews' => 'reviews',
    ];

    /** Core columns per table: column => app field. The rest lives in `data`. */
    public const COLUMNS = [
        'businesses' => ['nama' => 'name', 'bidang' => 'field'],
        'projects' => ['nama' => 'name', 'biz' => 'biz', 'stage' => 'stage', 'pic' => 'pic', 'due' => 'due'],
        'tasks' => ['nama' => 'name', 'biz' => 'biz', 'project' => 'project', 'owner' => 'owner', 'due' => 'due', 'done' => 'done'],
        'goals' => ['nama' => 'name', 'area' => 'area'],
        'dreams' => ['judul' => 'title', 'kategori' => 'cat', 'status' => 'status', 'tahun' => 'year'],
        'roadmap' => ['judul' => 'title', 'tahun' => 'year', 'done' => 'done'],
        'content' => ['judul' => 'title', 'channel' => 'channel', 'platform' => 'platform', 'stage' => 'stage', 'tanggal' => 'date'],
        'learning' => ['judul' => 'title', 'jenis' => 'type', 'status' => 'status'],
        'habits' => ['nama' => 'name', 'streak' => 'streak'],
        'events' => ['judul' => 'title', 'tanggal' => 'date', 'jenis' => 'type'],
        'assets' => ['nama' => 'name', 'kategori' => 'cat'],
        'reviews' => ['week_start' => 'weekStart'],
        'ledger' => ['bulan' => 'month', 'scope' => 'scope', 'income' => 'income', 'expense' => 'expense'],
    ];

    /** Nullable columns — "empty" vs "not filled in". */
    public const NULLABLE = ['dreams' => ['tahun'], 'roadmap' => ['tahun']];

    /** Numeric core columns (tinyint/int/double). */
    public const NUMERIC = ['tasks' => ['done'], 'roadmap' => ['done', 'tahun'], 'dreams' => ['tahun'], 'habits' => ['streak'], 'ledger' => ['income', 'expense']];

    /** Top-level keys that are not id-keyed collections; stored in `settings` as JSON. */
    public const SETTINGS_KEYS = ['firstRun', 'mood', 'energy', 'focus', 'weeklyTarget', 'auth', 'channels', 'dump'];

    private function db(): ConnectionInterface
    {
        return Modules::db('hlife');
    }

    /** Hlife cut over (#65): the Modul reads and writes the hlife_* tables of core. */
    public static function onCore(): bool
    {
        return Modules::connectionName('hlife') === 'core';
    }

    /** Physical table for a legacy table name on the current connection. */
    public static function table(string $legacy): string
    {
        if (! self::onCore()) {
            return $legacy;
        }

        return $legacy === 'settings' ? 'hlife_pengaturan' : 'hlife_'.$legacy;
    }

    /** The row key: the legacy id, kept in `legacy_id` on core. */
    public static function idCol(): string
    {
        return self::onCore() ? 'legacy_id' : 'id';
    }

    /** Defaults for missing settings rows — must match emptyState() in the frontend. */
    public static function settingDefault(string $k): mixed
    {
        return match ($k) {
            'firstRun' => true,
            'mood' => 3,
            'energy' => 4,
            'auth' => (object) ['enabled' => false, 'hash' => ''],
            'channels', 'dump' => [],
            default => '',
        };
    }

    // ───────────────────────────── read ──

    /** hl_ambil_semua — the full state S. */
    public function read(): array
    {
        $out = [];
        foreach (self::COLLECTIONS as $appKey => $table) {
            $out[$appKey] = $this->records($table);
        }
        // finance is only a { ledger: [...] } wrapper in the app.
        $out['finance'] = (object) ['ledger' => $this->records('ledger')];

        foreach ($this->db()->select('SELECT `k`, `v` FROM `'.self::table('settings').'`') as $r) {
            $out[$r->k] = json_decode($r->v);
        }
        foreach (self::SETTINGS_KEYS as $k) {
            if (! array_key_exists($k, $out)) {
                $out[$k] = self::settingDefault($k);
            }
        }

        return $out;
    }

    /** All decoded records of one table (ledger ordered by month, others in natural order, as legacy). */
    public function records(string $table): array
    {
        $order = $table === 'ledger' ? ' ORDER BY `bulan`' : '';
        $list = [];
        foreach ($this->db()->select('SELECT `data` FROM `'.self::table($table).'`'.$order) as $r) {
            $o = json_decode($r->data);
            if (is_object($o)) {
                $list[] = $o;
            }
        }

        return $list;
    }

    /** hl_stats — row count per table. */
    public function stats(): array
    {
        $out = [];
        foreach ([...array_keys(self::COLLECTIONS), 'ledger', 'settings'] as $t) {
            $out[$t] = (int) $this->db()->selectOne('SELECT COUNT(*) c FROM `'.self::table($t).'`')->c;
        }

        return $out;
    }

    // ───────────────────────────── write ──

    /**
     * hl_simpan_semua — replace the whole state. Every collection missing from
     * the payload is treated as [] (empties its table); only `tasks` must be an array.
     *
     * @return array{ok:bool, error?:string}
     */
    public function saveAll(mixed $data): array
    {
        // Guard against a broken payload: `tasks` marks a well-formed state.
        if (! $data instanceof stdClass || ! isset($data->tasks) || ! is_array($data->tasks)) {
            return ['ok' => false, 'error' => 'payload_rusak'];
        }

        $this->db()->transaction(function () use ($data) {
            foreach (self::COLLECTIONS as $appKey => $table) {
                $this->replaceTable($table, isset($data->$appKey) && is_array($data->$appKey) ? $data->$appKey : []);
            }
            $fin = $data->finance ?? null;
            $this->replaceTable('ledger', $fin instanceof stdClass && isset($fin->ledger) && is_array($fin->ledger) ? $fin->ledger : []);

            foreach (self::SETTINGS_KEYS as $k) {
                if (property_exists($data, $k)) {
                    $this->putSetting($k, $data->$k);
                }
            }
        });

        return ['ok' => true];
    }

    /**
     * resetAll (deploy/howandi_life "Reset data", G-13 / #187): empty every
     * collection, keep `auth` (the login hash), firstRun false — the same state
     * the old page built (emptyState()) and sent through saveAll.
     */
    public function resetAll(): array
    {
        $auth = $this->read()['auth'] ?? self::settingDefault('auth');
        $s = new stdClass;
        foreach (array_keys(self::COLLECTIONS) as $k) {
            $s->$k = [];
        }
        $s->finance = (object) ['ledger' => []];
        $s->firstRun = false;
        $s->mood = 3;
        $s->energy = 4;
        $s->focus = '';
        $s->weeklyTarget = '';
        $s->auth = $auth;
        $s->channels = [];
        $s->dump = [];

        return $this->saveAll($s);
    }

    /** hl_simpan_koleksi — upsert every record, then delete rows not in the list. */
    private function replaceTable(string $table, array $list): void
    {
        $ids = [];
        foreach ($list as $rec) {
            if (! $rec instanceof stdClass || ! isset($rec->id) || $rec->id === '') {
                continue;
            }
            $ids[] = $rec->id;
            $this->upsert($table, $rec, self::onCore());
        }

        // Rows missing from the payload were deleted in the UI (the client always sends everything).
        $key = self::idCol();
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $this->db()->delete('DELETE FROM `'.self::table($table)."` WHERE `$key` NOT IN ($ph)", $ids);
        } else {
            $this->db()->delete('DELETE FROM `'.self::table($table).'`');
        }
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATE of one record (core columns + full JSON).
     *
     * $coerce=false is the legacy connection: a missing/non-numeric value goes to a
     * numeric column as '' and the legacy server's sql_mode decides (non-strict
     * production stored 0). On core, or with $coerce=true, the coercion is explicit:
     * any non-numeric value becomes 0 and an explicit null stays NULL when nullable.
     *
     * On core the legacy id lives in `legacy_id`, a fresh ULID is minted per row,
     * the ms tech stamps are set by the server (legacy hlife had none), and every
     * write is accepted (last save wins, as legacy), so `version` bumps on each one.
     */
    public function upsert(string $table, stdClass $rec, bool $coerce = false): void
    {
        $map = self::COLUMNS[$table];
        $nulls = self::NULLABLE[$table] ?? [];
        $core = self::onCore();
        $cols = array_keys($map);

        $vals = [];
        foreach ($cols as $c) {
            $v = self::coreValue($rec, $map[$c], in_array($c, $nulls, true));
            // #97: core stays strict, so the coercion non-strict production did is
            // done here: any non-numeric value becomes 0 (an explicit null stays
            // NULL on a nullable column), matching what legacy stored.
            if (($coerce || $core) && in_array($c, self::NUMERIC[$table] ?? [], true)) {
                if (is_numeric($v)) {
                    $v = $v + 0;
                } elseif ($v !== null) {
                    $v = 0;
                }
            }
            if ($core) {
                $v = RowSync::fit($this->db(), self::table($table), $c, $v);
            }
            $vals[] = $v;
        }
        $vals[] = JsonDoc::encode($rec);

        if (self::onCore()) {
            $now = (int) round(microtime(true) * 1000);
            $names = ['id', 'legacy_id', ...$cols, 'data', 'updated_at', 'created_at', 'version'];
            $args = [strtolower((string) Str::ulid()), $rec->id, ...$vals, $now, $now, 1];
            $upd = implode(',', array_map(fn ($c) => "`$c`=VALUES(`$c`)", [...$cols, 'data']));
            $this->db()->insert(
                'INSERT INTO `'.self::table($table).'` (`'.implode('`,`', $names).'`) VALUES ('.implode(',', array_fill(0, count($names), '?')).')
                 ON DUPLICATE KEY UPDATE `version` = `version` + 1, '.$upd.', `updated_at` = VALUES(`updated_at`)',
                $args,
            );

            return;
        }

        $all = ['id', ...$cols, 'data'];
        $upd = implode(',', array_map(fn ($c) => "`$c`=VALUES(`$c`)", [...$cols, 'data']));
        $this->db()->insert(
            "INSERT INTO `$table` (`".implode('`,`', $all).'`) VALUES ('.implode(',', array_fill(0, count($all), '?')).")
             ON DUPLICATE KEY UPDATE $upd",
            [$rec->id, ...$vals],
        );
    }

    /** hl_nilai — scalar for a core column; nested values stay only in `data`. */
    private static function coreValue(stdClass $rec, string $field, bool $nullable): mixed
    {
        if (! property_exists($rec, $field)) {
            return $nullable ? null : '';
        }
        $v = $rec->$field;
        if ($v === null) {
            return $nullable ? null : '';
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_array($v) || is_object($v)) {
            return $nullable ? null : '';
        }

        return $v;
    }

    public function putSetting(string $k, mixed $value): void
    {
        if (self::onCore()) {
            // accepted write: the version bumps, like every hlife write (last save wins)
            $this->db()->insert(
                'INSERT INTO `'.self::table('settings').'` (`id`, `k`, `v`, `version`) VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE `v` = VALUES(`v`), `version` = `version` + 1',
                [strtolower((string) Str::ulid()), $k, json_encode($value, JSON_UNESCAPED_UNICODE)],
            );

            return;
        }
        $this->db()->insert(
            'INSERT INTO `settings` (`k`, `v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)',
            [$k, json_encode($value, JSON_UNESCAPED_UNICODE)],
        );
    }
}
