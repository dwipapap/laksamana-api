<?php

declare(strict_types=1);

namespace App\Core\Imports;

use App\Auth\AccountUser;
use App\Auth\OfficeAccess;
use App\Support\Divisi;
use App\Support\JsonDoc;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Identity cutover import (#43): account `users/modules/grants/admins/sessions`
 * plus `jadwal_setting` (heads, divOverride) and the Divisi word lists into the
 * identity tables of `core`. Mapping: docs/db/identity.md.
 *
 * Idempotent: rows are matched on `legacy_id` (or the natural key where the
 * legacy key is the row's own key: modul.kunci, divisi.kode, divisi_kata.kata,
 * sesi_legacy.token), unchanged rows are not touched, changed rows get
 * version + 1, and rows whose legacy source is gone are deleted. Sanctum
 * tokens owned by a legacy user id are re-keyed to that User's ULID.
 */
final class AccountImporter implements Importer
{
    public function module(): string
    {
        return 'account';
    }

    public function legacyConnections(): array
    {
        return ['legacy_account', 'legacy_jadwal'];
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
        $acc = DB::connection('legacy_account');
        $users = $acc->table('users')->orderBy('id')->get();
        $modules = $acc->table('modules')->orderBy('key')->get();
        $grants = $acc->table('grants')->orderBy('user_id')->orderBy('module')->get();
        $admins = $acc->table('admins')->orderBy('user_id')->orderBy('module')->get();
        $sessions = $acc->table('sessions')->orderBy('token')->get();
        $row = DB::connection('legacy_jadwal')->table('jadwal_setting')->where('id', 1)->first();
        $setting = JsonDoc::toArray($row ? JsonDoc::decode($row->data) : null);
        $heads = is_array($setting['heads'] ?? null) ? $setting['heads'] : [];
        $override = is_array($setting['divOverride'] ?? null) ? $setting['divOverride'] : [];

        $count = 0;
        $this->core()->transaction(function () use ($users, $modules, $grants, $admins, $sessions, $heads, $override, &$count): void {
            // 1. parents (no deletes yet: children must let go first)
            $userRows = [];
            foreach ($users as $u) {
                $userRows[(string) $u->id] = [
                    'nama' => (string) $u->name,
                    'username' => trim((string) $u->username) === '' ? null : (string) $u->username,
                    'nama_tampilan' => (string) $u->display_name,
                    'pin' => (string) $u->pin,
                    'aktif' => (int) $u->active,
                    'tim' => (string) $u->keterangan,
                    'no_hp' => (string) $u->no_hp,
                    'talenta_id' => (string) $u->talenta_id,
                    'cabang' => (string) $u->branch,
                    'organisasi' => (string) $u->organization,
                    'jabatan' => (string) $u->job_position,
                    'level_jabatan' => (string) $u->job_level,
                    'status_kerja' => (string) $u->employment_status,
                    'tanggal_bergabung' => self::date((string) $u->id, (string) $u->join_date),
                    'created_at' => (string) $u->created_at,
                    'updated_at' => (string) $u->updated_at,
                ];
            }
            $this->sync('user', 'legacy_id', $userRows, false);
            $uid = $this->ids('user', 'legacy_id');

            $terbatas = array_column(OfficeAccess::aturanTerbatas(), 'module');
            $modulRows = [];
            foreach ($modules as $m) {
                $modulRows[(string) $m->key] = [
                    'label' => (string) $m->label,
                    'aktif' => (int) $m->active,
                    'urutan' => (int) $m->urut,
                    'terbatas' => in_array((string) $m->key, $terbatas, true) ? 1 : 0,
                ];
            }
            $this->sync('modul', 'kunci', $modulRows, false);
            $mid = $this->ids('modul', 'kunci');

            // Divisi in legacy synonym order; codes only seen in jadwal_setting follow.
            $kodes = array_keys(Divisi::SYNONYMS);
            foreach ([...array_keys($heads), ...array_values($override)] as $k) {
                $k = is_scalar($k) ? trim((string) $k) : '';
                if ($k !== '' && $k !== Divisi::NONSHIFT && ! in_array($k, $kodes, true)) {
                    $kodes[] = $k;
                }
            }
            $divisiRows = [];
            foreach ($kodes as $i => $k) {
                $divisiRows[$k] = ['urutan' => $i + 1];
            }
            $this->sync('divisi', 'kode', $divisiRows, false);
            $did = $this->ids('divisi', 'kode');

            // 2. children, with deletes of rows whose source is gone
            $kataRows = [];
            foreach (Divisi::OFFICE_WORDS as $w) {
                $kataRows[$w] = ['divisi_id' => null];
            }
            foreach (Divisi::SYNONYMS as $k => $words) {
                foreach ($words as $w) {
                    $kataRows[$w] = ['divisi_id' => $did[$k]];
                }
            }
            $this->sync('divisi_kata', 'kata', $kataRows);

            $izin = $larangan = $admin = [];
            foreach ($grants as $g) {
                $key = $g->user_id.'|'.$g->module;
                $isAll = (string) $g->module === '*';
                if (! isset($uid[(string) $g->user_id]) || (! $isAll && ! isset($mid[(string) $g->module]))) {
                    throw new RuntimeException("grants [$key] points at a missing user or module.");
                }
                if (! $g->access && $isAll) {
                    throw new RuntimeException("grants [$key] is a Larangan for every Modul.");
                }
                $by = $uid[(string) $g->granted_by] ?? null; // 'import' / 'seed' / '' have no User
                $row = [
                    'user_id' => $uid[(string) $g->user_id],
                    'modul_id' => $isAll ? null : $mid[(string) $g->module],
                    'created_by' => $by,
                    'updated_by' => $by,
                    'created_at' => (string) $g->ts,
                    'updated_at' => (string) $g->ts,
                ];
                if ($g->access) {
                    $izin[$key] = $row;
                } else {
                    $larangan[$key] = $row;
                }
            }
            foreach ($admins as $a) {
                $key = $a->user_id.'|'.$a->module;
                $isAll = (string) $a->module === '*';
                if (! isset($uid[(string) $a->user_id]) || (! $isAll && ! isset($mid[(string) $a->module]))) {
                    throw new RuntimeException("admins [$key] points at a missing user or module.");
                }
                $admin[$key] = ['user_id' => $uid[(string) $a->user_id], 'modul_id' => $isAll ? null : $mid[(string) $a->module]];
            }
            $this->sync('izin_akses', 'legacy_id', $izin);
            $this->sync('larangan', 'legacy_id', $larangan);
            $this->sync('admin_modul', 'legacy_id', $admin);

            // A head or placement for a User who no longer exists is the stale id
            // the FKs now forbid (#2): it is dropped, not invented.
            $kepala = [];
            foreach ($heads as $div => $list) {
                foreach (is_array($list) ? $list : [] as $u) {
                    $u = is_scalar($u) ? trim((string) $u) : '';
                    if (isset($uid[$u], $did[(string) $div])) {
                        $kepala[$div.'|'.$u] = ['divisi_id' => $did[(string) $div], 'user_id' => $uid[$u]];
                    }
                }
            }
            $this->sync('kepala_divisi', 'legacy_id', $kepala);

            $penempatan = [];
            foreach ($override as $u => $div) {
                $div = is_scalar($div) ? trim((string) $div) : '';
                if (isset($uid[(string) $u]) && $div !== '') {
                    $penempatan[(string) $u] = ['user_id' => $uid[(string) $u], 'divisi_id' => $did[$div] ?? null];
                }
            }
            $this->sync('penempatan_divisi', 'legacy_id', $penempatan);

            $sesi = [];
            foreach ($sessions as $x) {
                if (isset($uid[(string) $x->user_id])) {
                    $sesi[(string) $x->token] = [
                        'user_id' => $uid[(string) $x->user_id],
                        'kedaluwarsa' => Carbon::createFromTimestampMs((int) $x->expiry, 'UTC')->format('Y-m-d H:i:s.v'),
                        'created_at' => (string) $x->dibuat,
                        'updated_at' => (string) $x->dibuat,
                    ];
                }
            }
            $this->sync('sesi_legacy', 'token', $sesi, true, false);

            // 3. parents whose source is gone
            $this->prune('divisi', 'kode', array_keys($divisiRows));
            $this->prune('modul', 'kunci', array_keys($modulRows));
            $this->prune('user', 'legacy_id', array_keys($userRows));

            // Sanctum tokens: legacy user id -> User ULID (a ULID owner is already re-keyed).
            foreach ($uid as $legacy => $ulid) {
                $this->core()->table('personal_access_tokens')
                    ->where('tokenable_type', (new AccountUser)->getMorphClass())->where('tokenable_id', $legacy)
                    ->update(['tokenable_id' => $ulid]);
            }

            $count = count($userRows) + count($modulRows) + count($divisiRows) + count($kataRows) + count($izin)
                + count($larangan) + count($admin) + count($kepala) + count($penempatan) + count($sesi);
        });

        return $count;
    }

    /**
     * Upsert rows keyed by $key: insert new ones with a fresh ULID and
     * version 1, update only changed ones (version + 1), optionally delete
     * rows whose key is no longer in the source.
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function sync(string $table, string $key, array $rows, bool $prune = true, bool $versioned = true): void
    {
        $db = $this->core();
        $existing = $db->table($table)->whereNotNull($key)->get()->keyBy($key);
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as $k => $cols) {
            $cur = $existing[$k] ?? null;
            if ($cur === null) {
                $new = [$key => $k, ...$cols];
                if ($versioned) {
                    $new = ['id' => strtolower((string) Str::ulid()), ...$new, 'version' => 1];
                }
                $new['created_at'] ??= $now;
                $new['updated_at'] ??= $now;
                $db->table($table)->insert($new);

                continue;
            }
            $changed = array_filter($cols, fn ($v, $c) => self::norm($cur->$c ?? null) !== self::norm($v), ARRAY_FILTER_USE_BOTH);
            if ($changed === []) {
                continue;
            }
            $changed['updated_at'] ??= $now;
            if ($versioned) {
                $changed['version'] = (int) $cur->version + 1;
            }
            $db->table($table)->where($key, $k)->update($changed);
        }
        if ($prune) {
            $this->prune($table, $key, array_keys($rows));
        }
    }

    /** @param  list<string|int>  $keep */
    private function prune(string $table, string $key, array $keep): void
    {
        $gone = $this->core()->table($table)->whereNotNull($key)->pluck($key)
            ->reject(fn ($k) => in_array((string) $k, array_map('strval', $keep), true));
        foreach ($gone->chunk(500) as $chunk) {
            $this->core()->table($table)->whereIn($key, $chunk->all())->delete();
        }
    }

    /** @return array<string,string> key => ULID */
    private function ids(string $table, string $key): array
    {
        return $this->core()->table($table)->pluck('id', $key)->mapWithKeys(fn ($id, $k) => [(string) $k => $id])->all();
    }

    private static function norm(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    private static function date(string $id, string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new RuntimeException("users [$id] join_date [$v] is not a date.");
        }

        return $v;
    }
}
