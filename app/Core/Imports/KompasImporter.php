<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kompas cutover import (#69): the omset blob and the Analytics document, the
 * Catatan Void / QRIS BRI accountability tables, the monthly investor reports
 * and the Analytics access matrix into the kompas_* tables of `core`, plus the
 * void percentages as the `void` settings document. Mapping: docs/db/kompas.md.
 *
 * Idempotent: rows are matched on `legacy_id` — the legacy id where the source
 * has one, else the legacy primary key (dp_id, peran.kunci) or the pair that is
 * the module's own key (bulan|jenis, kunci|halaman) because the legacy
 * auto-increment id never leaves the database. Unchanged rows are not touched,
 * changed rows get `version + 1`, and rows whose legacy source is gone are
 * deleted. A `#<legacy user id>` analytics kunci is resolved to its core User
 * in `user_id`, so run the account import first.
 */
final class KompasImporter implements Importer
{
    /** Row tables whose legacy id is the row's own id column. */
    private const TABLES = ['void_log', 'bri_mutasi'];

    /** One-row JSON documents: legacy table => core table. */
    private const DOCUMENTS = ['app_state' => 'kompas_app_state', 'an_state' => 'kompas_an_state'];

    public function module(): string
    {
        return 'kompas';
    }

    public function legacyConnections(): array
    {
        return ['legacy_kompas'];
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
        $legacy = DB::connection('legacy_kompas');
        $users = $this->core()->table('user')->pluck('id', 'legacy_id');

        $count = 0;
        $this->core()->transaction(function () use ($legacy, $users, &$count): void {
            // The two one-row documents. legacy_id '1' is the only id either ever has.
            foreach (self::DOCUMENTS as $from => $to) {
                $r = $legacy->table($from)->where('id', 1)->first();
                if (! $r) {
                    continue;
                }
                $this->sync($to, ['1' => ['data' => (string) $r->data, 'oleh' => (string) $r->updated_by,
                    'updated_at' => (int) $r->updated_at, 'created_at' => (int) $r->updated_at]]);
                $count++;
            }

            // The settings document: legacy void_setting (one row) as `void`.
            $s = $legacy->table('void_setting')->where('id', 1)->first();
            if ($s) {
                $doc = json_encode(['tax_persen' => (float) $s->tax_persen, 'service_persen' => (float) $s->service_persen,
                    'updated_at' => (int) $s->updated_at, 'oleh' => (string) $s->updated_by],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->sync('kompas_pengaturan', ['void' => ['legacy_id' => '1', 'k' => 'void', 'v' => $doc,
                    'updated_at' => (int) $s->updated_at, 'created_at' => (int) $s->updated_at]], 'k');
                $count++;
            }

            // Rows whose legacy id is their own id column. The legacy millisecond
            // dibuat/diubah stamps become the ADR-0003 created_at/updated_at, and
            // `oleh_id` (the session user, an Office account id) its core User.
            foreach (self::TABLES as $t) {
                $wanted = [];
                foreach ($legacy->table($t)->orderBy('id')->get() as $r) {
                    $a = (array) $r;
                    $id = (string) $a['id'];
                    unset($a['id']);
                    $oleh = (string) ($a['oleh_id'] ?? '');
                    $wanted[$id] = [...$a, 'user_id' => $oleh === '' ? null : ($users[$oleh] ?? null),
                        'created_at' => (int) $a['dibuat'], 'updated_at' => (int) $a['diubah']];
                }
                $this->sync('kompas_'.$t, $wanted);
                $count += count($wanted);
            }

            // bri_dp_abai: the legacy primary key is dp_id.
            $wanted = [];
            foreach ($legacy->table('bri_dp_abai')->orderBy('dp_id')->get() as $r) {
                $a = (array) $r;
                $wanted[(string) $a['dp_id']] = [...$a, 'created_at' => (int) $a['abai_at'], 'updated_at' => (int) $a['abai_at']];
            }
            $this->sync('kompas_bri_dp_abai', $wanted);
            $count += count($wanted);

            // inv_lapor: (bulan, jenis) is the module's own key (and the upload's
            // ON DUPLICATE KEY target); the legacy counter id is not.
            $wanted = [];
            foreach ($legacy->table('inv_lapor')->orderBy('id')->get() as $r) {
                $wanted[$r->bulan.'|'.$r->jenis] = ['bulan' => (string) $r->bulan, 'jenis' => (string) $r->jenis,
                    'kunci' => (string) $r->kunci, 'nama' => (string) $r->nama, 'ukuran' => (int) $r->ukuran,
                    'at' => (int) $r->at, 'oleh' => (string) $r->oleh,
                    'created_at' => (int) $r->at, 'updated_at' => (int) $r->at];
            }
            $this->sync('kompas_inv_lapor', $wanted);
            $count += count($wanted);

            // The Analytics page × actor matrix: `#<legacy user id>` names a User.
            $wanted = [];
            foreach ($legacy->table('an_akses')->orderBy('id')->get() as $r) {
                $wanted[$r->kunci.'|'.$r->halaman] = ['kunci' => (string) $r->kunci, 'halaman' => (string) $r->halaman,
                    'tingkat' => (int) $r->tingkat, 'user_id' => self::userId((string) $r->kunci, $users)];
            }
            $this->sync('kompas_an_akses', $wanted);
            $count += count($wanted);

            $wanted = [];
            foreach ($legacy->table('an_peran')->get() as $r) {
                $wanted[(string) $r->kunci] = ['kunci' => (string) $r->kunci, 'peran' => (string) $r->peran,
                    'user_id' => self::userId((string) $r->kunci, $users)];
            }
            $this->sync('kompas_an_peran', $wanted);
            $count += count($wanted);
        });

        return $count;
    }

    /** The core User a `#<legacy id>` analytics kunci names (null: a role or `@<name>` key). */
    private static function userId(string $kunci, Collection $users): ?string
    {
        $k = str_starts_with($kunci, '#') ? substr($kunci, 1) : '';

        return $k === '' ? null : ($users[$k] ?? null);
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
