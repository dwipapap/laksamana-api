<?php

namespace App\Modules\Hr\Services;

use App\Support\Modules;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Staff Performance (HR / People OS) — port of hr-mysql/lib_hr_mysql.php.
 *
 * One whole-document revision (`meta.rev`) guards every save: the client
 * sends the rev it loaded, and a newer server rev refuses the save. Check
 * and write happen in one transaction under SELECT … FOR UPDATE on meta.
 *
 * JSON is decoded WITHOUT assoc: empty maps (kpiActuals, monthlyInputs,
 * reviews.layers) must stay `{}` or the frontend silently loses data.
 *
 * `employees.data` holds personal data and access PINs: nothing in this
 * module logs payloads or query bindings.
 *
 * attendance_months / attendance_days exist in schema.sql but not in the
 * live database. Their presence is checked read-only; without them the
 * attendance map is kept in the `extra:attendance` setting, which is where
 * production stores it today (see attendanceTables()).
 */
class HrState
{
    /** App collection => table. `audit` is append-only and handled separately. */
    public const COLLECTIONS = [
        'divisions' => 'divisions',
        'employees' => 'employees',
        'kpiTemplates' => 'kpi_templates',
        'okrs' => 'okrs',
        'reviews' => 'reviews',
        'competencies' => 'competencies',
        'trainings' => 'trainings',
        'trainingRecords' => 'training_records',
        'coachings' => 'coachings',
        'rewards' => 'rewards',
        'badges' => 'badges',
        'violations' => 'violations',
        'feedbacks' => 'feedbacks',
        'careerPaths' => 'career_paths',
        'successions' => 'successions',
        'moods' => 'moods',
        'suggestions' => 'suggestions',
        'calendar' => 'calendar',
    ];

    /** Indexed columns per table: column => app field. `data` is the source of truth. */
    public const COLUMNS = [
        'divisions' => ['nama' => 'name', 'warna' => 'color'],
        'employees' => ['nama' => 'name', 'jabatan' => 'role', 'div_id' => 'divId', 'tingkat' => 'level',
            'app_role' => 'appRole', 'join_date' => 'joinDate', 'status' => 'status'],
        'kpi_templates' => ['div_id' => 'divId'],
        'okrs' => ['owner_type' => 'ownerType', 'owner_id' => 'ownerId', 'periode' => 'period'],
        'reviews' => ['emp_id' => 'empId', 'bulan' => 'month', 'status' => 'status'],
        'competencies' => ['emp_id' => 'empId'],
        'trainings' => ['judul' => 'title', 'div_id' => 'divId', 'jenis' => 'type', 'mandatory' => 'mandatory'],
        'training_records' => ['training_id' => 'trainingId', 'emp_id' => 'empId', 'status' => 'status', 'skor' => 'score', 'tanggal' => 'date'],
        'coachings' => ['emp_id' => 'empId', 'coach_id' => 'coachId', 'tanggal' => 'date', 'status' => 'status'],
        'rewards' => ['emp_id' => 'empId', 'tanggal' => 'date', 'jenis' => 'type', 'points' => 'points'],
        'badges' => ['emp_id' => 'empId', 'bulan' => 'month', 'badge' => 'badge'],
        'violations' => ['emp_id' => 'empId', 'tanggal' => 'date', 'jenis' => 'type', 'severity' => 'severity', 'sp' => 'sp', 'status' => 'status'],
        'feedbacks' => ['emp_id' => 'empId', 'tanggal' => 'date', 'kind' => 'kind'],
        'career_paths' => ['track' => 'track'],
        'successions' => ['posisi' => 'position', 'emp_id' => 'empId', 'readiness' => 'readiness'],
        'moods' => ['emp_id' => 'empId', 'tanggal' => 'date', 'mood' => 'mood'],
        'suggestions' => ['emp_id' => 'empId', 'tanggal' => 'date', 'status' => 'status'],
        'calendar' => ['tanggal' => 'date', 'kind' => 'kind', 'judul' => 'title'],
    ];

    /** Nullable on purpose: an anonymous suggestion MUST store emp_id NULL. */
    public const NULLABLE = ['suggestions' => ['emp_id'], 'moods' => ['mood'], 'training_records' => ['skor']];

    /** Top-level keys that are NOT stored as `extra:<key>` settings. */
    private const NOT_EXTRA = ['audit', 'kpiActuals', 'monthlyInputs', 'attendance', 'settings', 'version', '_rev', '_savedAt', '_savedBy'];

    private ?bool $attendance = null;

    public function db(): ConnectionInterface
    {
        return Modules::db('hr');
    }

    public static function enc(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    /** PHP's (string) cast without the "Array to string" warning Laravel turns into an exception. */
    public static function s(mixed $v): string
    {
        return is_array($v) ? 'Array' : (string) $v;
    }

    /** Whether the attendance tables exist (read-only information_schema check, cached per request). */
    public function attendanceTables(): bool
    {
        return $this->attendance ??= (int) $this->db()->selectOne(
            "SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('attendance_months','attendance_days')"
        )->c === 2;
    }

    // ───────────────────────────── read ──

    /** hr_ambil_semua — the full document S. */
    public function read(): array
    {
        $db = $this->db();
        $out = [];
        foreach (self::COLLECTIONS as $appKey => $table) {
            $out[$appKey] = $this->records($table);
        }

        // audit: newest first — the frontend unshift()s.
        $out['audit'] = array_map(fn ($r) => (object) [
            'id' => $r->id, 'at' => $r->at, 'userId' => $r->user_id,
            'userName' => $r->user_name, 'action' => $r->action, 'detail' => $r->detail ?? '',
        ], $db->select('SELECT `id`, `at`, `user_id`, `user_name`, `action`, `detail` FROM `audit` ORDER BY `at` DESC, `id` DESC'));

        $out['kpiActuals'] = $this->kpiActuals();
        $out['monthlyInputs'] = $this->monthlyInputs();
        $out['attendance'] = $this->attendance();

        $set = [];
        foreach ($db->select('SELECT `k`, `v` FROM `settings`') as $r) {
            $v = json_decode($r->v);
            if (str_starts_with($r->k, 'extra:')) {
                $out[substr($r->k, 6)] = $v;   // unknown top-level key, kept so it is not lost
            } else {
                $set[$r->k] = $v;
            }
        }
        $out['settings'] = (object) $set;    // a MAP: empty must be {}

        $m = $this->meta();
        $out['version'] = $m ? (int) $m->versi : 1;
        $out['_rev'] = $m ? (int) $m->rev : 0;
        $out['_savedAt'] = $m ? $m->saved_at : '';
        $out['_savedBy'] = $m ? $m->saved_by : '';

        return $out;
    }

    /** @return list<stdClass> */
    public function records(string $table): array
    {
        $list = [];
        foreach ($this->db()->select("SELECT `data` FROM `$table`") as $r) {
            $o = json_decode($r->data);
            if (is_object($o)) {
                $list[] = $o;
            }
        }

        return $list;
    }

    /** { divId: { month: { itemId: number|null } } } — objects at every level. */
    public function kpiActuals(): stdClass
    {
        $ka = [];
        foreach ($this->db()->select('SELECT `div_id`, `bulan`, `item_id`, `nilai` FROM `kpi_actuals`') as $r) {
            $ka[$r->div_id][$r->bulan][$r->item_id] = $r->nilai === null ? null : (float) $r->nilai;
        }
        foreach ($ka as $d => $perMonth) {
            foreach ($perMonth as $b => $perItem) {
                $ka[$d][$b] = (object) $perItem;
            }
            $ka[$d] = (object) $ka[$d];
        }

        return (object) $ka;
    }

    /** { empId: { month: {...} } } */
    public function monthlyInputs(): stdClass
    {
        $mi = [];
        foreach ($this->db()->select('SELECT `emp_id`, `bulan`, `data` FROM `monthly_inputs`') as $r) {
            $o = json_decode($r->data);
            $mi[$r->emp_id][$r->bulan] = is_object($o) ? $o : (object) [];
        }
        foreach ($mi as $e => $perMonth) {
            $mi[$e] = (object) $perMonth;
        }

        return (object) $mi;
    }

    /** { month: {fileName, importedAt, importedBy, unmatched[], days[]} } */
    public function attendance(): stdClass
    {
        if (! $this->attendanceTables()) {
            // production without the tables: the map lives in the `extra:attendance` setting
            $v = $this->db()->selectOne("SELECT `v` FROM `settings` WHERE `k` = 'extra:attendance'");
            $map = $v ? json_decode($v->v) : null;

            return $map instanceof stdClass ? $map : new stdClass;
        }
        $att = [];
        foreach ($this->db()->select('SELECT `bulan`, `data`, `imported_at`, `imported_by` FROM `attendance_months`') as $r) {
            $o = json_decode($r->data);
            if (! is_object($o)) {
                $o = (object) [];
            }
            $o->importedAt = $r->imported_at;
            $o->importedBy = $r->imported_by;
            $o->days = [];
            $att[$r->bulan] = $o;
        }
        foreach ($this->db()->select('SELECT `bulan`, `talenta_id`, `tanggal`, `emp_id`, `data` FROM `attendance_days` ORDER BY `talenta_id`, `tanggal`') as $r) {
            if (! isset($att[$r->bulan])) {
                continue; // orphan day without its month
            }
            $d = json_decode($r->data);
            if (! is_object($d)) {
                $d = (object) [];
            }
            $d->talentaId = $r->talenta_id;
            $d->date = $r->tanggal;
            $d->empId = $r->emp_id;
            $att[$r->bulan]->days[] = $d;
        }

        return (object) $att;
    }

    public function meta(bool $lock = false): ?stdClass
    {
        return $this->db()->selectOne('SELECT `rev`, `saved_at`, `saved_by`, `versi` FROM `meta` WHERE `id`=1'.($lock ? ' FOR UPDATE' : ''));
    }

    /** hr_stats */
    public function stats(): array
    {
        $db = $this->db();
        $out = [];
        foreach (self::COLLECTIONS as $appKey => $table) {
            $out[$appKey] = (int) $db->selectOne("SELECT COUNT(*) c FROM `$table`")->c;
        }
        foreach (['audit', 'kpi_actuals', 'monthly_inputs', 'attendance_months', 'attendance_days', 'settings'] as $t) {
            $out[$t] = str_starts_with($t, 'attendance_') && ! $this->attendanceTables()
                ? 0 : (int) $db->selectOne("SELECT COUNT(*) c FROM `$t`")->c;
        }
        $m = $this->meta();
        $out['_rev'] = $m ? (int) $m->rev : 0;
        $out['_savedAt'] = $m ? $m->saved_at : '';
        $out['_savedBy'] = $m ? $m->saved_by : '';
        $out['env'] = Modules::envLabel();
        $out['db'] = Modules::databaseName('hr');

        return $out;
    }

    // ───────────────────────────── saveAll ──

    /**
     * hr_simpan_semua.
     *
     * @return array{ok:true,rev:int}|array{ok:false,error:string,savedBy?:string,savedAt?:string,rev?:int}
     */
    public function saveAll(mixed $data, mixed $baseRev, mixed $by): array
    {
        // Never empty the tables because of a broken payload. employees may be [].
        if (! $data instanceof stdClass || ! isset($data->employees) || ! is_array($data->employees)) {
            return ['ok' => false, 'error' => 'payload_kosong'];
        }

        return $this->underRev($baseRev, function () use ($data, $by) {
            foreach (self::COLLECTIONS as $appKey => $table) {
                $this->replaceCollection($table, isset($data->$appKey) && is_array($data->$appKey) ? $data->$appKey : []);
            }
            $this->appendAudit($data->audit ?? []);
            $this->replaceKpiActuals($data->kpiActuals ?? null);
            $this->replaceMonthly($data->monthlyInputs ?? null);
            $this->writeAttendance($data->attendance ?? null);
            $this->replaceSettings($data);

            return [self::s($by), isset($data->version) ? self::int($data->version) : 1];
        });
    }

    /**
     * Run $write inside one transaction after the rev check under FOR UPDATE.
     * $write returns [savedBy, versi|null]; versi null keeps the stored value.
     *
     * @return array{ok:true,rev:int}|array{ok:false,error:'conflict',savedBy:string,savedAt:string,rev:int}
     */
    public function underRev(mixed $baseRev, \Closure $write): array
    {
        return $this->db()->transaction(function () use ($baseRev, $write) {
            $m = $this->meta(true);
            if (! $m) {
                $this->db()->insert("INSERT INTO `meta` (`id`, `rev`, `saved_at`, `saved_by`, `versi`) VALUES (1,0,'','',1)");
                $m = (object) ['rev' => 0, 'saved_at' => '', 'saved_by' => '', 'versi' => 1];
            }
            $revServer = (int) $m->rev;
            $conflict = ['ok' => false, 'error' => 'conflict', 'savedBy' => $m->saved_by, 'savedAt' => $m->saved_at, 'rev' => $revServer];

            // baseRev null = the client never knew a rev; only allowed while the server is empty.
            if ($baseRev === null || $baseRev === '') {
                if ($revServer > 0) {
                    return $conflict;
                }
            } elseif (self::int($baseRev) !== $revServer) {
                return $conflict;
            }

            [$by, $versi] = $write();
            $rev = $revServer + 1;
            $this->db()->update('UPDATE `meta` SET `rev`=?, `saved_at`=?, `saved_by`=?, `versi`=? WHERE `id`=1',
                [$rev, gmdate('Y-m-d\TH:i:s.000\Z'), $by, $versi ?? (int) ($m->versi ?? 1)]);

            return ['ok' => true, 'rev' => $rev];
        });
    }

    /** (int) cast as PHP does it, without the warning an object would raise. */
    public static function int(mixed $v): int
    {
        return is_object($v) ? 1 : (int) $v;
    }

    /** hr_nilai — scalar for an indexed column; nested values stay only in `data`. */
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

    /** INSERT … ON DUPLICATE KEY UPDATE of one record. */
    public function upsert(string $table, stdClass $rec): void
    {
        $map = self::COLUMNS[$table];
        $nulls = self::NULLABLE[$table] ?? [];
        $cols = array_keys($map);
        $vals = [$rec->id];
        foreach ($cols as $c) {
            $vals[] = self::coreValue($rec, $map[$c], in_array($c, $nulls, true));
        }
        $vals[] = self::enc($rec);
        $all = ['id', ...$cols, 'data'];
        $upd = implode(',', array_map(fn ($c) => "`$c`=VALUES(`$c`)", [...$cols, 'data']));
        $this->db()->insert("INSERT INTO `$table` (`".implode('`,`', $all).'`) VALUES ('
            .implode(',', array_fill(0, count($all), '?')).") ON DUPLICATE KEY UPDATE $upd", $vals);
    }

    /** hr_simpan_koleksi — upsert, then DELETE NOT IN (everything when the list is empty). */
    private function replaceCollection(string $table, array $list): void
    {
        $ids = [];
        foreach ($list as $rec) {
            if (! $rec instanceof stdClass || ! isset($rec->id) || $rec->id === '') {
                continue;
            }
            $ids[] = $rec->id;
            $this->upsert($table, $rec);
        }
        if ($ids) {
            $this->db()->delete("DELETE FROM `$table` WHERE id NOT IN (".implode(',', array_fill(0, count($ids), '?')).')', $ids);
        } else {
            $this->db()->delete("DELETE FROM `$table`");
        }
    }

    public function replaceKpiActuals(mixed $map): void
    {
        $this->db()->delete('DELETE FROM `kpi_actuals`');
        if (! is_object($map)) {
            return;
        }
        foreach ($map as $divId => $perMonth) {
            if (! is_object($perMonth)) {
                continue;
            }
            foreach ($perMonth as $bulan => $perItem) {
                if (! is_object($perItem)) {
                    continue;
                }
                foreach ($perItem as $itemId => $nilai) {
                    $this->putKpiActual((string) $divId, (string) $bulan, (string) $itemId, $nilai);
                }
            }
        }
    }

    public function putKpiActual(string $divId, string $bulan, string $itemId, mixed $nilai): void
    {
        $this->db()->insert('INSERT INTO `kpi_actuals` (`div_id`, `bulan`, `item_id`, `nilai`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `nilai`=VALUES(`nilai`)',
            [$divId, $bulan, $itemId, is_numeric($nilai) ? $nilai : null]);
    }

    public function replaceMonthly(mixed $map): void
    {
        $this->db()->delete('DELETE FROM `monthly_inputs`');
        if (! is_object($map)) {
            return;
        }
        foreach ($map as $empId => $perMonth) {
            if (! is_object($perMonth)) {
                continue;
            }
            foreach ($perMonth as $bulan => $isi) {
                $this->putMonthly((string) $empId, (string) $bulan, $isi);
            }
        }
    }

    public function putMonthly(string $empId, string $bulan, mixed $isi): void
    {
        $this->db()->insert('INSERT INTO `monthly_inputs` (`emp_id`, `bulan`, `data`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `data`=VALUES(`data`)',
            [$empId, $bulan, self::enc($isi)]);
    }

    /**
     * hr_simpan_attendance — per month. Months missing from a NON-empty map are
     * deleted; an empty map deletes nothing. A month's days are replaced only
     * when days are sent.
     */
    public function writeAttendance(mixed $map, bool $pruneMissing = true): void
    {
        if (! is_object($map)) {
            return;
        }
        if (! $this->attendanceTables()) {
            return; // stored as the `extra:attendance` setting instead (replaceSettings)
        }
        $db = $this->db();
        $keep = array_map('strval', array_keys(get_object_vars($map)));
        if ($pruneMissing && $keep) {
            $ph = implode(',', array_fill(0, count($keep), '?'));
            $db->delete("DELETE FROM `attendance_months` WHERE `bulan` NOT IN ($ph)", $keep);
            $db->delete("DELETE FROM `attendance_days` WHERE `bulan` NOT IN ($ph)", $keep);
        }
        foreach ($map as $bulan => $isi) {
            if (! is_object($isi)) {
                continue;
            }
            $bulan = (string) $bulan;
            $days = isset($isi->days) && is_array($isi->days) ? $isi->days : [];

            $summary = clone $isi;
            unset($summary->days, $summary->importedAt, $summary->importedBy);
            $db->insert('INSERT INTO `attendance_months` (`bulan`, `data`, `imported_at`, `imported_by`) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE `data`=VALUES(`data`), `imported_at`=VALUES(`imported_at`), `imported_by`=VALUES(`imported_by`)',
                [$bulan, self::enc($summary), self::s($isi->importedAt ?? ''), self::s($isi->importedBy ?? '')]);

            if ($days) {
                $db->delete('DELETE FROM `attendance_days` WHERE `bulan`=?', [$bulan]);
                foreach ($days as $d) {
                    if (! is_object($d)) {
                        continue;
                    }
                    $tid = self::s($d->talentaId ?? '');
                    $tgl = self::s($d->date ?? '');
                    if ($tid === '' || $tgl === '') {
                        continue;
                    }
                    $row = clone $d;
                    unset($row->talentaId, $row->date, $row->empId);
                    $db->insert('INSERT INTO `attendance_days` (`bulan`, `talenta_id`, `tanggal`, `emp_id`, `data`) VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE `emp_id`=VALUES(`emp_id`), `data`=VALUES(`data`)',
                        [$bulan, $tid, $tgl, self::s($d->empId ?? ''), self::enc($row)]);
                }
            }
        }
    }

    /** hr_simpan_audit — append-only: existing entries are never touched. */
    public function appendAudit(mixed $list): void
    {
        if (! is_array($list)) {
            return;
        }
        foreach ($list as $r) {
            if (! is_object($r) || ! isset($r->id) || $r->id === '') {
                continue;
            }
            $this->db()->insert('INSERT INTO `audit` (`id`, `at`, `user_id`, `user_name`, `action`, `detail`) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `id`=`id`',
                [$r->id, self::s($r->at ?? ''), self::s($r->userId ?? ''), self::s($r->userName ?? ''), self::s($r->action ?? ''), self::s($r->detail ?? '')]);
        }
    }

    /** hr_simpan_settings — settings map + unknown top-level keys as `extra:<key>`. */
    private function replaceSettings(stdClass $data): void
    {
        $skip = [...array_keys(self::COLLECTIONS), ...self::NOT_EXTRA];
        $this->db()->delete('DELETE FROM `settings`');
        if (isset($data->settings) && is_object($data->settings)) {
            foreach ($data->settings as $k => $v) {
                $this->putSetting((string) $k, $v);
            }
        }
        foreach ($data as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            $this->putSetting('extra:'.$k, $v);
        }
        // Without the attendance tables, production keeps the attendance map as the
        // `extra:attendance` setting (the older backend treated it as an unknown
        // key). Keep doing that — otherwise the DELETE above would erase the history.
        if (! $this->attendanceTables() && property_exists($data, 'attendance')) {
            $this->putSetting('extra:attendance', $data->attendance);
        }
    }

    public function putSetting(string $k, mixed $v): void
    {
        $this->db()->insert('INSERT INTO `settings` (`k`, `v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)', [$k, self::enc($v)]);
    }
}
