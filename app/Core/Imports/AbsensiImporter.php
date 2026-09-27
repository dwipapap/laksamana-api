<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Absensi cutover import (#53): abs_lokasi, abs_wajah, abs_punch verbatim
 * and the abs_setting blob into the absensi tables of `core`.
 * Mapping: docs/db/absensi.md.
 *
 * Faces keep their natural key ('USER:<id>'/'DW:<id>') in `legacy_id`;
 * punches keep their generated id the same way. Rows are matched on
 * `legacy_id`, unchanged rows are not touched, changed rows get
 * `version + 1`, and rows whose legacy source is gone are deleted.
 */
final class AbsensiImporter implements Importer
{
    public function module(): string
    {
        return 'absensi';
    }

    public function legacyConnections(): array
    {
        return ['legacy_absensi'];
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
        $legacy = DB::connection('legacy_absensi');
        $lokasi = $legacy->table('abs_lokasi')->orderBy('id')->get();
        $wajah = $legacy->table('abs_wajah')->orderBy('subjek')->get();
        $punch = $legacy->table('abs_punch')->orderBy('id')->get();
        $setting = $legacy->table('abs_setting')->where('id', 1)->first();

        $count = 0;
        $this->core()->transaction(function () use ($lokasi, $wajah, $punch, $setting, &$count): void {
            $lw = [];
            foreach ($lokasi as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id'], $a['updated_by']);
                $lw[$id] = $a;
            }
            $this->sync('abs_lokasi', $lw);

            $ww = [];
            foreach ($wajah as $r) {
                $a = (array) $r;
                $id = (string) $a['subjek'];
                unset($a['subjek']);
                $ww[$id] = $a;
            }
            $this->sync('abs_wajah', $ww, 'legacy_id');

            $pw = [];
            foreach ($punch as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $pw[$id] = $a;
            }
            $this->sync('abs_punch', $pw);

            $sw = [];
            if ($setting) {
                $sw['1'] = ['data' => (string) $setting->data, 'updated_at' => (int) $setting->updated_at];
            }
            $this->sync('abs_setting', $sw);

            $count = count($lw) + count($ww) + count($pw) + count($sw);
        });

        return $count;
    }

    /**
     * Upsert rows keyed by $key: insert new ones with a fresh ULID and
     * version 1, update only changed ones (version + 1), delete rows whose
     * key is no longer in the source. Mirrors JadwalImporter::sync (#47).
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
            $changed = array_filter($cols, fn ($v, $c) => self::norm($cur->$c ?? null) !== self::norm($v), ARRAY_FILTER_USE_BOTH);
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
