<?php

declare(strict_types=1);

namespace App\Core\Imports;

use App\Support\JsonDoc;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jadwal cutover import (#47): jadwal_sel, jadwal_pengajuan and the
 * normalised setting (shifts, jabatan, shiftKru, manajemen, maksBeruntun,
 * jedaMin) from the legacy jadwal database into the jadwal tables of `core`.
 * Mapping: docs/db/jadwal.md.
 *
 * Heads and Penempatan Divisi are NOT imported here; they already live in
 * identity via `core:import account` (#43), which must run first: every
 * user-linked row resolves its User ULID through `user.legacy_id`, and rows
 * for Users that no longer exist are dropped (FK), not invented.
 *
 * Idempotent: rows are matched on `legacy_id` (or the natural key `kode`),
 * unchanged rows are not touched, changed rows get `version + 1`, and rows
 * whose legacy source is gone are deleted.
 */
final class JadwalImporter implements Importer
{
    public function module(): string
    {
        return 'jadwal';
    }

    public function legacyConnections(): array
    {
        return ['legacy_jadwal'];
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
        $legacy = DB::connection('legacy_jadwal');
        $sel = $legacy->table('jadwal_sel')->orderBy('user_id')->orderBy('tgl')->get();
        $aju = $legacy->table('jadwal_pengajuan')->orderBy('id')->get();
        $row = $legacy->table('jadwal_setting')->where('id', 1)->first();
        $setting = JsonDoc::toArray($row ? JsonDoc::decode($row->data) : null);

        $count = 0;
        $this->core()->transaction(function () use ($sel, $aju, $setting, &$count): void {
            $uid = $this->core()->table('user')->pluck('id', 'legacy_id')
                ->mapWithKeys(fn ($id, $k) => [(string) $k => $id])->all();

            // 1. cells (PK user_id,tgl -> legacy_id "uid|tgl")
            $selRows = [];
            foreach ($sel as $r) {
                $u = trim((string) $r->user_id);
                $t = (string) $r->tgl;
                if (! isset($uid[$u]) || $t === '') {
                    continue; // stale crew id: dropped, like identity heads (#43)
                }
                $selRows[$u.'|'.$t] = [
                    'user_id' => $uid[$u],
                    'tgl' => $t,
                    'shift' => (string) $r->shift,
                    'jam_mulai' => (string) $r->jam_mulai,
                    'jam_selesai' => (string) $r->jam_selesai,
                    'catatan' => (string) $r->catatan,
                ];
            }
            $this->sync('jadwal_sel', 'legacy_id', $selRows);

            // 2. requests (legacy `id` -> legacy_id, `user_id` -> User ULID)
            $ajuRows = [];
            foreach ($aju as $r) {
                $u = trim((string) $r->user_id);
                if (! isset($uid[$u])) {
                    continue;
                }
                $ajuRows[(string) $r->id] = [
                    'user_id' => $uid[$u],
                    'jenis' => (string) $r->jenis,
                    'tgl_mulai' => (string) $r->tgl_mulai,
                    'tgl_selesai' => (string) $r->tgl_selesai,
                    'alasan' => $r->alasan === null ? null : (string) $r->alasan,
                    'status' => (string) $r->status,
                    'dibuat_at' => (int) $r->dibuat_at,
                    'dibuat_oleh' => (string) $r->dibuat_oleh,
                    'putus_at' => (int) $r->putus_at,
                    'putus_oleh' => (string) $r->putus_oleh,
                    'putus_nota' => (string) $r->putus_nota,
                    'shift' => (string) ($r->shift ?? ''),
                    'jam_mulai' => (string) ($r->jam_mulai ?? ''),
                    'jam_selesai' => (string) ($r->jam_selesai ?? ''),
                    'head_at' => (int) ($r->head_at ?? 0),
                    'head_oleh' => (string) ($r->head_oleh ?? ''),
                ];
            }
            $this->sync('jadwal_pengajuan', 'legacy_id', $ajuRows);

            // 3. normalised setting (heads/divOverride already in identity)
            $shifts = is_array($setting['shifts'] ?? null) ? $setting['shifts'] : [];
            $shiftRows = [];
            foreach ($shifts as $kode => $def) {
                $kode = is_scalar($kode) ? trim((string) $kode) : '';
                if ($kode === '') {
                    continue;
                }
                $def = is_array($def) ? $def : [];
                $known = ['n', 'm', 's', 'w', 'libur', 'urut'];
                $ekstra = array_diff_key($def, array_flip($known));
                $str = fn (string $k, int $max) => array_key_exists($k, $def) && is_scalar($def[$k]) ? mb_substr((string) $def[$k], 0, $max) : null;
                $shiftRows[$kode] = [
                    'nama' => $str('n', 32),
                    'jam_mulai' => $str('m', 5),
                    'jam_selesai' => $str('s', 5),
                    'warna' => $str('w', 16),
                    'libur' => array_key_exists('libur', $def) ? (! empty($def['libur']) ? 1 : 0) : null,
                    'urutan' => array_key_exists('urut', $def) ? (int) $def['urut'] : null,
                    'ekstra' => $ekstra === [] ? null : json_encode($ekstra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
            $this->sync('jadwal_shift', 'kode', $shiftRows);

            $jabRows = [];
            foreach (is_array($setting['jabatan'] ?? null) ? $setting['jabatan'] : [] as $u => $j) {
                $u = is_scalar($u) ? trim((string) $u) : '';
                if ($u !== '' && isset($uid[$u]) && is_scalar($j) && trim((string) $j) !== '') {
                    $jabRows[$u] = ['user_id' => $uid[$u], 'jabatan' => (string) $j];
                }
            }
            $this->sync('jadwal_jabatan', 'legacy_id', $jabRows);

            $kruRows = [];
            foreach (is_array($setting['shiftKru'] ?? null) ? $setting['shiftKru'] : [] as $u => $k) {
                $u = is_scalar($u) ? trim((string) $u) : '';
                if ($u !== '' && isset($uid[$u]) && is_scalar($k) && trim((string) $k) !== '') {
                    $kruRows[$u] = ['user_id' => $uid[$u], 'kode_shift' => (string) $k];
                }
            }
            $this->sync('jadwal_shift_kru', 'legacy_id', $kruRows);

            $manRows = [];
            $pos = 0;
            foreach (is_array($setting['manajemen'] ?? null) ? $setting['manajemen'] : [] as $u) {
                $u = is_scalar($u) ? trim((string) $u) : '';
                if ($u !== '' && isset($uid[$u]) && ! isset($manRows[$u])) {
                    $manRows[$u] = ['user_id' => $uid[$u], 'urutan' => ++$pos];
                }
            }
            $this->sync('jadwal_manajemen', 'legacy_id', $manRows);

            // Scalars + `template`/unknown keys. NULL scalars stay absent
            // on the wire; an empty `template` list becomes {} (PETA rule).
            $knownTop = ['shifts', 'heads', 'divOverride', 'jabatan', 'shiftKru',
                'maksBeruntun', 'jedaMin', 'manajemen'];
            $ekstra = array_diff_key($setting, array_flip($knownTop));
            if (array_key_exists('template', $ekstra) && $ekstra['template'] === []) {
                $ekstra['template'] = new \stdClass;
            }
            $this->sync('jadwal_pengaturan', 'legacy_id', ['1' => [
                'maks_beruntun' => array_key_exists('maksBeruntun', $setting) ? (int) $setting['maksBeruntun'] : null,
                'jeda_menit' => array_key_exists('jedaMin', $setting) ? (int) $setting['jedaMin'] : null,
                'ekstra' => $ekstra === [] ? null : json_encode($ekstra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]]);

            $count = count($selRows) + count($ajuRows) + count($shiftRows) + count($jabRows)
                + count($kruRows) + count($manRows) + 1;
        });

        return $count;
    }

    /**
     * Upsert rows keyed by $key: insert new ones with a fresh ULID and
     * version 1, update only changed ones (version + 1), delete rows whose
     * key is no longer in the source. Mirrors AccountImporter::sync (#43).
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function sync(string $table, string $key, array $rows, bool $prune = true): void
    {
        $db = $this->core();
        $existing = $db->table($table)->whereNotNull($key)->get()->keyBy($key);
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as $k => $cols) {
            $cur = $existing[$k] ?? null;
            if ($cur === null) {
                $new = [$key => $k, ...$cols];
                if (! isset($new['id'])) {
                    $new = ['id' => strtolower((string) Str::ulid()), ...$new];
                }
                $new += ['version' => 1, 'created_at' => $now, 'updated_at' => $now];
                $db->table($table)->insert($new);

                continue;
            }
            $changed = array_filter($cols, fn ($v, $c) => self::norm($cur->$c ?? null) !== self::norm($v), ARRAY_FILTER_USE_BOTH);
            if ($changed === []) {
                continue;
            }
            $changed['updated_at'] = $now;
            $changed['version'] = (int) $cur->version + 1;
            $db->table($table)->where($key, $k)->update($changed);
        }
        if ($prune) {
            $gone = $db->table($table)->whereNotNull($key)->pluck($key)
                ->reject(fn ($k) => in_array((string) $k, array_map('strval', array_keys($rows)), true));
            foreach ($gone->chunk(500) as $chunk) {
                $db->table($table)->whereIn($key, $chunk->all())->delete();
            }
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
