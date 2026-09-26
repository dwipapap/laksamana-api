<?php

declare(strict_types=1);

namespace App\Core\Imports;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Marketing cutover import (#49): every row table verbatim (indexed columns
 * + the full `data` JSON), the two id-keyed lists that lived inside
 * `settings` (`vip`, `designreqs`) as child tables, and every remaining
 * settings document into `marketing_pengaturan`. Mapping: docs/db/marketing.md.
 *
 * Idempotent: rows are matched on `legacy_id` (or the natural key `k` for
 * pengaturan), unchanged rows are not touched, changed rows get
 * `version + 1`, and rows whose legacy source is gone are deleted.
 */
final class MarketingImporter implements Importer
{
    public function module(): string
    {
        return 'marketing';
    }

    public function legacyConnections(): array
    {
        return ['legacy_marketing'];
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
        $legacy = DB::connection('legacy_marketing');
        // Legacy tables, in MarketingSchema::collections() order, plus the
        // append-only timeline and the settings documents.
        $tables = ['clients', 'events', 'followups', 'approvals', 'users', 'staff',
            'task_templates', 'task_categories', 'categories', 'notifs'];
        $rows = [];
        foreach ($tables as $t) {
            $rows[$t] = $legacy->table($t)->orderBy('id')->get();
        }
        $activities = $legacy->table('activities')->orderBy('id')->get();
        $settings = $legacy->table('settings')->orderBy('k')->get();

        $count = 0;
        $this->core()->transaction(function () use ($rows, $activities, $settings, &$count): void {
            $map = [
                'clients' => 'marketing_klien',
                'events' => 'marketing_acara',
                'followups' => 'marketing_tindak_lanjut',
                'approvals' => 'marketing_persetujuan',
                'users' => 'marketing_pengguna',
                'staff' => 'marketing_staf',
                'task_templates' => 'marketing_template_tugas',
                'task_categories' => 'marketing_kategori_tugas',
                'categories' => 'marketing_kategori',
                'notifs' => 'marketing_notifikasi',
            ];
            foreach ($map as $from => $to) {
                $wanted = [];
                foreach ($rows[$from] as $r) {
                    $a = (array) $r;
                    $id = (string) $a['id'];
                    unset($a['id']);
                    $wanted[$id] = $a;
                }
                $this->sync($to, $wanted);
                $count += count($wanted);
            }

            $wantAct = [];
            foreach ($activities as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $wantAct[$id] = $a;
            }
            $this->sync('marketing_aktivitas', $wantAct);
            $count += count($wantAct);

            $docs = [];
            foreach ($settings as $r) {
                $docs[(string) $r->k] = (string) $r->v;
            }
            $vip = self::rowsOf($docs['extra:vip'] ?? null);
            $design = self::rowsOf($docs['extra:designreqs'] ?? null);
            unset($docs['extra:vip'], $docs['extra:designreqs']);

            $this->sync('marketing_vip', self::listed($vip, ['tanggal', 'jenis']));
            $count += count($vip);
            $this->sync('marketing_permintaan_desain', self::listed($design, []));
            $count += count($design);

            $wantSet = [];
            foreach ($docs as $k => $v) {
                $wantSet[$k] = ['v' => $v];
            }
            $this->sync('marketing_pengaturan', $wantSet, 'k');
            $count += count($wantSet);
        });

        return $count;
    }

    /** Decode a settings-held row list; a corrupt value is an empty list, never a failed import. */
    private static function rowsOf(?string $json): array
    {
        $d = is_string($json) ? json_decode($json, true) : null;

        return is_array($d) ? array_values($d) : [];
    }

    /**
     * Rows of a settings-held collection keyed by row id, with the derived
     * index columns and the insert-only list position (`urutan`).
     *
     * @param  list<mixed>  $list
     * @param  list<string>  $dates  row fields stored as DATE columns
     * @return array<string,array<string,mixed>>
     */
    private static function listed(array $list, array $dates): array
    {
        $out = [];
        $pos = 0;
        foreach ($list as $r) {
            if (! is_array($r) || ! isset($r['id']) || $r['id'] === '') {
                continue;
            }
            $id = (string) $r['id'];
            if (isset($out[$id])) {
                continue;
            }
            $cols = [
                'updated_at' => self::ms($r['updatedAt'] ?? 0),
                'urutan' => ++$pos,
                'data' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            foreach ($dates as $f) {
                $v = $r[$f] ?? null;
                $cols[$f] = $f === 'tanggal'
                    ? (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) ? trim($v) : null)
                    : (is_scalar($v) ? (string) $v : null);
            }
            $out[$id] = $cols;
        }

        return $out;
    }

    private static function ms(mixed $v): int
    {
        if (is_int($v) || is_float($v)) {
            return (int) $v;
        }
        if (is_string($v) && $v !== '') {
            if (ctype_digit($v)) {
                return (int) $v;
            }
            $ts = strtotime($v);
            if ($ts !== false) {
                return $ts * 1000;
            }
        }

        return 0;
    }

    /**
     * Upsert rows keyed by $key: insert new ones with a fresh ULID and
     * version 1, update only changed ones (version + 1), delete rows whose
     * key is no longer in the source. Mirrors JadwalImporter::sync (#47);
     * the `data`/`v` payloads compare decoded, so re-encoding drift never
     * counts as a change.
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
                $new = ['id' => strtolower((string) Str::ulid()), $key => $k, ...$cols, 'version' => 1];
                $db->table($table)->insert($new);

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
