<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DW cutover import (#51): dw_pekerja, dw_ajuan, dw_permintaan verbatim and
 * the dw_setting blob into the dw tables of `core`. Mapping: docs/db/dw.md.
 *
 * dw_login_gagal / dw_sesi are legacy login remnants the port ignores
 * (docs/modules/dw.md) and are not imported. Workers, assignments and
 * requests keep their legacy ids (soft links, no FKs); rows are matched on
 * `legacy_id`, unchanged rows are not touched, changed rows get
 * `version + 1`, and rows whose legacy source is gone are deleted.
 */
final class DwImporter implements Importer
{
    public function module(): string
    {
        return 'dw';
    }

    public function legacyConnections(): array
    {
        return ['legacy_dw'];
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
        $legacy = DB::connection('legacy_dw');
        $pekerja = $legacy->table('dw_pekerja')->orderBy('id')->get();
        $ajuan = $legacy->table('dw_ajuan')->orderBy('id')->get();
        $minta = $legacy->table('dw_permintaan')->orderBy('id')->get();
        $setting = $legacy->table('dw_setting')->where('id', 1)->first();

        $count = 0;
        $this->core()->transaction(function () use ($pekerja, $ajuan, $minta, $setting, &$count): void {
            $pw = [];
            foreach ($pekerja as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $pw[$id] = $a;
            }
            $this->sync('dw_pekerja', $pw);

            $aw = [];
            foreach ($ajuan as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $aw[$id] = $a;
            }
            $this->sync('dw_ajuan', $aw);

            $mw = [];
            foreach ($minta as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $mw[$id] = $a;
            }
            $this->sync('dw_permintaan', $mw);

            $sw = [];
            if ($setting) {
                // The actor NAME is not carried (never on the wire); the
                // ULID actors cover it. See docs/db/dw.md.
                $sw['1'] = ['data' => (string) $setting->data, 'updated_at' => (int) $setting->updated_at];
            }
            $this->sync('dw_setting', $sw);

            $count = count($pw) + count($aw) + count($mw) + count($sw);
        });

        return $count;
    }

    /**
     * Upsert rows keyed by legacy id: insert new ones with a fresh ULID and
     * version 1, update only changed ones (version + 1), delete rows whose
     * legacy source is gone. Mirrors JadwalImporter::sync (#47).
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function sync(string $table, array $rows): void
    {
        $db = $this->core();
        $existing = $db->table($table)->whereNotNull('legacy_id')->get()->keyBy('legacy_id');
        foreach ($rows as $k => $cols) {
            $cur = $existing[$k] ?? null;
            if ($cur === null) {
                $db->table($table)->insert(['id' => strtolower((string) Str::ulid()), 'legacy_id' => $k, ...$cols, 'version' => 1]);

                continue;
            }
            $changed = array_filter($cols, fn ($v, $c) => self::norm($cur->$c ?? null) !== self::norm($v), ARRAY_FILTER_USE_BOTH);
            if ($changed === []) {
                continue;
            }
            $changed['version'] = (int) $cur->version + 1;
            $db->table($table)->where('legacy_id', $k)->update($changed);
        }
        $gone = $db->table($table)->whereNotNull('legacy_id')->pluck('legacy_id')
            ->reject(fn ($k) => in_array((string) $k, array_map('strval', array_keys($rows)), true));
        foreach ($gone->chunk(500) as $chunk) {
            $db->table($table)->whereIn('legacy_id', $chunk->all())->delete();
        }
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
