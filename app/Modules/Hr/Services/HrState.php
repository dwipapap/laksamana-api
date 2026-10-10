<?php

namespace App\Modules\Hr\Services;

use App\Support\Modules;
use App\Support\RowSync;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
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
 *
 * Core (#63, DB_HR_CONNECTION=core): the same SQL runs on the hr_* tables of
 * `core` (t()), rows keyed by `legacy_id` (idCol()), the attendance tables
 * always present, and `hr_meta.version` holding the legacy rev. The wire
 * shapes are identical. Mapping: docs/db/hr.md.
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

    /** Numeric core columns: a non-numeric value that non-strict MySQL turned into 0 must be cast (#97). */
    public const NUMERIC = ['trainings' => ['mandatory'], 'training_records' => ['skor'], 'rewards' => ['points'], 'moods' => ['mood']];

    /** Top-level keys that are NOT stored as `extra:<key>` settings. */
    private const NOT_EXTRA = ['audit', 'kpiActuals', 'monthlyInputs', 'attendance', 'settings', 'version', '_rev', '_savedAt', '_savedBy'];

    private ?bool $attendance = null;

    public function db(): ConnectionInterface
    {
        return Modules::db('hr');
    }

    /** HR cut over (#63): the Modul reads and writes the hr_* tables of core. */
    public static function onCore(): bool
    {
        return Modules::connectionName('hr') === 'core';
    }

    /** Physical table for a legacy table name on the current connection. */
    public static function t(string $table): string
    {
        if (! self::onCore()) {
            return $table;
        }

        return $table === 'settings' ? 'hr_pengaturan' : 'hr_'.$table;
    }

    /** Record id column: the legacy id, or `legacy_id` on core. */
    public static function idCol(): string
    {
        return self::onCore() ? 'legacy_id' : 'id';
    }

    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
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
        if (self::onCore()) {
            return true;
        }

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
        ], $db->select('SELECT `'.self::idCol().'` AS `id`, `at`, `user_id`, `user_name`, `action`, `detail` FROM `'.self::t('audit').'` ORDER BY `at` DESC, `'.self::idCol().'` DESC'));

        $out['kpiActuals'] = $this->kpiActuals();
        $out['monthlyInputs'] = $this->monthlyInputs();
        $out['attendance'] = $this->attendance();

        $set = [];
        foreach ($db->select('SELECT `k`, `v` FROM `'.self::t('settings').'` ORDER BY `k`') as $r) {
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
        foreach ($this->db()->select('SELECT `data` FROM `'.self::t($table).'` ORDER BY `'.self::idCol().'`') as $r) {
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
        foreach ($this->db()->select('SELECT `div_id`, `bulan`, `item_id`, `nilai` FROM `'.self::t('kpi_actuals').'` ORDER BY `div_id`, `bulan`, `item_id`') as $r) {
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
        foreach ($this->db()->select('SELECT `emp_id`, `bulan`, `data` FROM `'.self::t('monthly_inputs').'` ORDER BY `emp_id`, `bulan`') as $r) {
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
        foreach ($this->db()->select('SELECT `bulan`, `data`, `imported_at`, `imported_by` FROM `'.self::t('attendance_months').'` ORDER BY `bulan`') as $r) {
            $o = json_decode($r->data);
            if (! is_object($o)) {
                $o = (object) [];
            }
            $o->importedAt = $r->imported_at;
            $o->importedBy = $r->imported_by;
            $o->days = [];
            $att[$r->bulan] = $o;
        }
        foreach ($this->db()->select('SELECT `bulan`, `talenta_id`, `tanggal`, `emp_id`, `data` FROM `'.self::t('attendance_days').'` ORDER BY `talenta_id`, `tanggal`') as $r) {
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
        $sql = self::onCore()
            ? 'SELECT `version` AS `rev`, `saved_at`, `saved_by`, `versi` FROM `hr_meta` WHERE `legacy_id`=1'
            : 'SELECT `rev`, `saved_at`, `saved_by`, `versi` FROM `meta` WHERE `id`=1';

        return $this->db()->selectOne($sql.($lock ? ' FOR UPDATE' : ''));
    }

    /** hr_stats */
    public function stats(): array
    {
        $db = $this->db();
        $out = [];
        foreach (self::COLLECTIONS as $appKey => $table) {
            $out[$appKey] = (int) $db->selectOne('SELECT COUNT(*) c FROM `'.self::t($table).'`')->c;
        }
        foreach (['audit', 'kpi_actuals', 'monthly_inputs', 'attendance_months', 'attendance_days', 'settings'] as $t) {
            $out[$t] = str_starts_with($t, 'attendance_') && ! $this->attendanceTables()
                ? 0 : (int) $db->selectOne('SELECT COUNT(*) c FROM `'.self::t($t).'`')->c;
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

    // ───────────────────────────── admin: import / reset (G-13, #187) ──

    /**
     * importJson (deploy/hr Pengaturan "Impor Backup"): the whole state from a
     * backup file REPLACES the current one through saveAll, as the old page did
     * (S = data; save()). A file without `employees` and `settings` is refused
     * like legacy ('format'). One audit row names who did it. Attendance months
     * missing from the file survive, exactly as with any legacy save.
     */
    public function importAll(mixed $data, string $userId, string $by): array
    {
        if (! $data instanceof stdClass || ! isset($data->employees) || ! is_array($data->employees) || ! isset($data->settings) || ! is_object($data->settings)) {
            return ['ok' => false, 'error' => 'format'];
        }

        return $this->replaceWhole($data, $userId, $by, 'Impor backup', 'Seluruh data diganti dari berkas backup');
    }

    /** resetAll (deploy/hr "Reset ke data awal"): back to seed() — divisions, KPI templates, career paths; no crew. */
    public function resetAll(string $userId, string $by): array
    {
        return $this->replaceWhole(self::seed(), $userId, $by, 'Reset data', 'Seluruh data dikembalikan ke data awal');
    }

    private function replaceWhole(stdClass $data, string $userId, string $by, string $action, string $detail): array
    {
        $data->audit = array_values(array_filter(is_array($data->audit ?? null) ? $data->audit : [], 'is_object'));
        $data->audit[] = (object) ['id' => 'au_'.substr(bin2hex(random_bytes(6)), 0, 10), 'at' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'userId' => $userId, 'userName' => $by, 'action' => $action, 'detail' => $detail];
        $m = $this->meta();

        return $this->saveAll($data, $m ? (int) $m->rev : null, $by);
    }

    /**
     * uid() of deploy/hr: 7 base-36 characters. Legacy seed() passes a prefix
     * ('kpit', 'ki', 'cp') that its uid() ignores — so does this one.
     */
    private static function uid(string $ignored = ''): string
    {
        $s = '';
        for ($i = 0; $i < 7; $i++) {
            $s .= '0123456789abcdefghijklmnopqrstuvwxyz'[random_int(0, 35)];
        }

        return $s;
    }

    /**
     * seed() of deploy/hr/index.html — the "data awal". The crew list is EMPTY on
     * purpose (legacy comment: made-up rows with real names once reached production).
     */
    public static function seed(): stdClass
    {
        $k = fn (string $divId, array $items) => (object) ['id' => self::uid('kpit'), 'divId' => $divId,
            'items' => array_map(fn ($it) => (object) ['id' => self::uid('ki'), 'name' => $it[0], 'weight' => $it[1], 'target' => $it[2], 'unit' => $it[3], 'dir' => $it[4]], $items)];
        $obj = fn (array $a) => (object) $a;

        return $obj([
            'version' => 1,
            'settings' => $obj([
                'venueName' => 'Laksamana Muda Coffee & Live Space',
                'scoreWeights' => $obj(['attendance' => 20, 'kpi' => 30, 'training' => 10, 'review' => 15, 'discipline' => 10, 'teamwork' => 10, 'initiative' => 5]),
                'grades' => array_map($obj, [['g' => 'A+', 'min' => 90], ['g' => 'A', 'min' => 85], ['g' => 'B+', 'min' => 80], ['g' => 'B', 'min' => 70], ['g' => 'C', 'min' => 60], ['g' => 'D', 'min' => 0]]),
                'violationDeduct' => $obj(['Ringan' => 5, 'Sedang' => 15, 'Berat' => 30]),
                'spRules' => $obj(['ringanToSP1' => 3, 'spValidMonths' => 6]),
                'passingGrade' => 80,
                'reviewLayerWeights' => $obj(['self' => 10, 'manager' => 40, 'hr' => 20, 'ceo' => 30]),
                'defaultComponentScore' => 75,
                'pointRules' => $obj(['badge' => 50, 'trainingPass' => 20, 'eom' => 150]),
                'attendanceRules' => $obj([
                    'lateToleranceMin' => 15, 'lateCapMin' => 45, 'noCheckoutCredit' => 0.5,
                    'excusedCaps' => $obj(['I' => 3, 'S' => 2]),
                    'excusedCodes' => ['OTL', 'CT', 'CKM'],
                    'offShifts' => ['dayoff', 'National Holiday'],
                    'offShiftContains' => ['dayoff', 'day off', 'libur', 'holiday'],
                    'weights' => $obj(['presence' => 70, 'punctuality' => 30]),
                ]),
            ]),
            'divisions' => array_map($obj, [
                ['id' => 'd_mgmt', 'name' => 'Management', 'color' => '#0B1F33'],
                ['id' => 'd_kitchen', 'name' => 'Kitchen', 'color' => '#C0392B'],
                ['id' => 'd_bar', 'name' => 'Bar', 'color' => '#B8893A'],
                ['id' => 'd_store', 'name' => 'Store / Service', 'color' => '#1E8E5A'],
                ['id' => 'd_marketing', 'name' => 'Marketing & Digital', 'color' => '#2563EB'],
                ['id' => 'd_finance', 'name' => 'Finance', 'color' => '#7C3AED'],
                ['id' => 'd_event', 'name' => 'Event', 'color' => '#DB2777'],
                ['id' => 'd_hrga', 'name' => 'HR / GA / Legal', 'color' => '#0891B2'],
            ]),
            'employees' => [],
            'kpiTemplates' => [
                $k('d_kitchen', [['Food Cost', 30, 30, '%', 'down'], ['Kecepatan Penyajian (menit)', 15, 15, 'mnt', 'down'], ['Waste', 20, 3, '%', 'down'], ['Hygiene Audit', 20, 90, 'skor', 'up'], ['Kepatuhan SOP', 15, 90, 'skor', 'up']]),
                $k('d_bar', [['Beverage Cost', 30, 22, '%', 'down'], ['Speed of Service (menit)', 20, 7, 'mnt', 'down'], ['Upselling', 25, 15, 'juta', 'up'], ['Konsistensi Rasa (QC pass)', 25, 90, '%', 'up']]),
                $k('d_marketing', [['Reach (ribu)', 20, 500, 'rb', 'up'], ['Leads / Inquiry', 25, 60, 'leads', 'up'], ['Konten Terbit', 20, 30, 'konten', 'up'], ['Closing Sponsor/Partner', 35, 50, 'juta', 'up']]),
                $k('d_finance', [['Ketepatan Laporan (on-time)', 35, 100, '%', 'up'], ['Umur Piutang (hari)', 30, 14, 'hari', 'down'], ['Cash Accuracy', 35, 100, '%', 'up']]),
                $k('d_store', [['Omzet vs Target', 30, 100, '%', 'up'], ['Komplain Pelanggan (kasus)', 20, 2, 'kasus', 'down'], ['Rating Google', 25, 4.7, '★', 'up'], ['Repeat Customer', 25, 30, '%', 'up']]),
                $k('d_event', [['Event Terlaksana', 25, 4, 'event', 'up'], ['Uplift Revenue Event', 35, 100, '% target', 'up'], ['Kepuasan Klien Event', 25, 90, 'skor', 'up'], ['On-time Rundown', 15, 90, '%', 'up']]),
            ],
            'kpiActuals' => new stdClass,
            'monthlyInputs' => new stdClass,
            'attendance' => new stdClass,
            'okrs' => [], 'reviews' => [], 'competencies' => [], 'trainings' => [], 'trainingRecords' => [],
            'coachings' => [], 'rewards' => [], 'badges' => [], 'violations' => [], 'feedbacks' => [],
            'careerPaths' => [
                $obj(['id' => self::uid('cp'), 'track' => 'Service', 'steps' => ['Junior Waiter', 'Senior Waiter', 'Captain', 'Supervisor', 'Store Manager'],
                    'req' => 'Attendance >95%, KPI >85, Training wajib lulus, Tanpa SP aktif, Min. 1 tahun di level']),
                $obj(['id' => self::uid('cp'), 'track' => 'Bar', 'steps' => ['Bar Crew', 'Barista', 'Senior Barista', 'Head Bar'],
                    'req' => 'Sertifikasi Coffee Knowledge, KPI >85, Kompetensi rata-rata ≥4★']),
                $obj(['id' => self::uid('cp'), 'track' => 'Kitchen', 'steps' => ['Cook Helper', 'Cook', 'Senior Cook', 'Sous Chef', 'Head Chef'],
                    'req' => 'Hygiene Test lulus, Food cost dalam target 3 bulan, Tanpa SP aktif']),
            ],
            'successions' => [], 'moods' => [], 'suggestions' => [], 'calendar' => [], 'audit' => [],
        ]);
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
                self::onCore()
                    ? $this->db()->insert("INSERT INTO `hr_meta` (`id`, `legacy_id`, `version`, `saved_at`, `saved_by`, `versi`) VALUES (?,1,0,'','',1)", [self::ulid()])
                    : $this->db()->insert("INSERT INTO `meta` (`id`, `rev`, `saved_at`, `saved_by`, `versi`) VALUES (1,0,'','',1)");
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
            $byStored = self::onCore() ? RowSync::fit($this->db(), 'hr_meta', 'saved_by', $by) : $by;
            $this->db()->update(self::onCore()
                ? 'UPDATE `hr_meta` SET `version`=?, `saved_at`=?, `saved_by`=?, `versi`=? WHERE `legacy_id`=1'
                : 'UPDATE `meta` SET `rev`=?, `saved_at`=?, `saved_by`=?, `versi`=? WHERE `id`=1',
                [$rev, gmdate('Y-m-d\TH:i:s.000\Z'), $byStored, $versi ?? (int) ($m->versi ?? 1)]);

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
        $nums = self::NUMERIC[$table] ?? [];
        $core = self::onCore();
        $cols = array_keys($map);
        $vals = [$rec->id];
        foreach ($cols as $c) {
            $v = self::coreValue($rec, $map[$c], in_array($c, $nulls, true));
            if ($core) {
                if (in_array($c, $nums, true)) {
                    $v = is_numeric($v) ? $v + 0 : (in_array($c, $nulls, true) ? null : 0);
                }
                $v = RowSync::fit($this->db(), self::t($table), $c, $v);
            }
            $vals[] = $v;
        }
        $vals[] = self::enc($rec);
        if (self::onCore()) {
            $this->upsertCore($table, $cols, $vals);

            return;
        }
        $all = ['id', ...$cols, 'data'];
        $upd = implode(',', array_map(fn ($c) => "`$c`=VALUES(`$c`)", [...$cols, 'data']));
        $this->db()->insert("INSERT INTO `$table` (`".implode('`,`', $all).'`) VALUES ('
            .implode(',', array_fill(0, count($all), '?')).") ON DUPLICATE KEY UPDATE $upd", $vals);
    }

    /**
     * Core upsert keyed by legacy_id: a new row gets a ULID, `version` bumps
     * only when `data` changes (the columns derive from it), and an employee
     * is linked to the Office User with the same legacy id, when one exists.
     */
    private function upsertCore(string $table, array $cols, array $vals): void
    {
        $all = ['legacy_id', ...$cols, 'data'];
        $ph = array_fill(0, count($all), '?');
        $upd = ['`version`=`version`+(`data`<>VALUES(`data`))', ...array_map(fn ($c) => "`$c`=VALUES(`$c`)", [...$cols, 'data'])];
        if ($table === 'employees') {
            $all[] = 'user_id';
            $ph[] = '(SELECT u.`id` FROM `user` u WHERE u.`legacy_id` = ?)';
            $vals[] = $vals[0];
            $upd[] = '`user_id`=VALUES(`user_id`)';
        }
        $this->db()->insert('INSERT INTO `'.self::t($table).'` (`id`,`'.implode('`,`', $all).'`) VALUES (?,'.implode(',', $ph)
            .') ON DUPLICATE KEY UPDATE '.implode(',', $upd), [self::ulid(), ...$vals]);
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
        $t = self::t($table);
        if ($ids) {
            $this->db()->delete("DELETE FROM `$t` WHERE `".self::idCol().'` NOT IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids);
        } else {
            $this->db()->delete("DELETE FROM `$t`");
        }
    }

    public function replaceKpiActuals(mixed $map): void
    {
        $this->db()->delete('DELETE FROM `'.self::t('kpi_actuals').'`');
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
        $nilai = is_numeric($nilai) ? $nilai : null;
        if (self::onCore()) {
            $db = $this->db();
            $t = self::t('kpi_actuals');
            $this->db()->insert('INSERT INTO `hr_kpi_actuals` (`id`, `legacy_id`, `div_id`, `bulan`, `item_id`, `nilai`) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE `version`=`version`+(NOT (`nilai`<=>VALUES(`nilai`))), `nilai`=VALUES(`nilai`)',
                [self::ulid(), "$divId|$bulan|$itemId", RowSync::fit($db, $t, 'div_id', $divId),
                    RowSync::fit($db, $t, 'bulan', $bulan), RowSync::fit($db, $t, 'item_id', $itemId), $nilai]);

            return;
        }
        $this->db()->insert('INSERT INTO `kpi_actuals` (`div_id`, `bulan`, `item_id`, `nilai`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `nilai`=VALUES(`nilai`)',
            [$divId, $bulan, $itemId, $nilai]);
    }

    public function replaceMonthly(mixed $map): void
    {
        $this->db()->delete('DELETE FROM `'.self::t('monthly_inputs').'`');
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
        if (self::onCore()) {
            $db = $this->db();
            $t = self::t('monthly_inputs');
            $this->db()->insert('INSERT INTO `hr_monthly_inputs` (`id`, `legacy_id`, `emp_id`, `bulan`, `data`) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE `version`=`version`+(`data`<>VALUES(`data`)), `data`=VALUES(`data`)',
                [self::ulid(), "$empId|$bulan", RowSync::fit($db, $t, 'emp_id', $empId),
                    RowSync::fit($db, $t, 'bulan', $bulan), self::enc($isi)]);

            return;
        }
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
        $core = self::onCore();
        $months = self::t('attendance_months');
        $daysT = self::t('attendance_days');
        $keep = array_map('strval', array_keys(get_object_vars($map)));
        if ($pruneMissing && $keep) {
            $ph = implode(',', array_fill(0, count($keep), '?'));
            $db->delete("DELETE FROM `$months` WHERE `bulan` NOT IN ($ph)", $keep);
            $db->delete("DELETE FROM `$daysT` WHERE `bulan` NOT IN ($ph)", $keep);
        }
        foreach ($map as $bulan => $isi) {
            if (! is_object($isi)) {
                continue;
            }
            $bulan = (string) $bulan;
            $days = isset($isi->days) && is_array($isi->days) ? $isi->days : [];

            $summary = clone $isi;
            unset($summary->days, $summary->importedAt, $summary->importedBy);
            $bulanF = RowSync::fit($db, $months, 'bulan', $bulan);
            $monthVals = [$bulanF, self::enc($summary),
                RowSync::fit($db, $months, 'imported_at', self::s($isi->importedAt ?? '')),
                RowSync::fit($db, $months, 'imported_by', self::s($isi->importedBy ?? ''))];
            $core
                ? $db->insert('INSERT INTO `hr_attendance_months` (`id`, `legacy_id`, `bulan`, `data`, `imported_at`, `imported_by`) VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE `version`=`version`+(`data`<>VALUES(`data`) OR `imported_at`<>VALUES(`imported_at`) OR `imported_by`<>VALUES(`imported_by`)),
                    `data`=VALUES(`data`), `imported_at`=VALUES(`imported_at`), `imported_by`=VALUES(`imported_by`)',
                    [self::ulid(), $bulanF, ...$monthVals])
                : $db->insert('INSERT INTO `attendance_months` (`bulan`, `data`, `imported_at`, `imported_by`) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE `data`=VALUES(`data`), `imported_at`=VALUES(`imported_at`), `imported_by`=VALUES(`imported_by`)',
                    $monthVals);

            if ($days) {
                $db->delete("DELETE FROM `$daysT` WHERE `bulan`=?", [$bulan]);
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
                    $dayVals = [RowSync::fit($db, $daysT, 'bulan', $bulan),
                        RowSync::fit($db, $daysT, 'talenta_id', $tid),
                        RowSync::fit($db, $daysT, 'tanggal', $tgl),
                        RowSync::fit($db, $daysT, 'emp_id', self::s($d->empId ?? '')), self::enc($row)];
                    $core
                        ? $db->insert('INSERT INTO `hr_attendance_days` (`id`, `legacy_id`, `month_id`, `bulan`, `talenta_id`, `tanggal`, `emp_id`, `data`)
                            VALUES (?,?,(SELECT m.`id` FROM `hr_attendance_months` m WHERE m.`legacy_id` = ?),?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE `emp_id`=VALUES(`emp_id`), `data`=VALUES(`data`)',
                            [self::ulid(), RowSync::fit($db, $daysT, 'legacy_id', "$bulan|$tid|$tgl"),
                                $bulanF, ...$dayVals])
                        : $db->insert('INSERT INTO `attendance_days` (`bulan`, `talenta_id`, `tanggal`, `emp_id`, `data`) VALUES (?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE `emp_id`=VALUES(`emp_id`), `data`=VALUES(`data`)',
                            $dayVals);
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
            $vals = [$r->id, self::s($r->at ?? ''), self::s($r->userId ?? ''), self::s($r->userName ?? ''), self::s($r->action ?? ''), self::s($r->detail ?? '')];
            if (self::onCore()) {
                $db = $this->db();
                $t = self::t('audit');
                $this->db()->insert('INSERT INTO `hr_audit` (`id`, `legacy_id`, `at`, `user_id`, `user_name`, `action`, `detail`) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `legacy_id`=`legacy_id`',
                    [self::ulid(), $vals[0], RowSync::fit($db, $t, 'at', $vals[1]), RowSync::fit($db, $t, 'user_id', $vals[2]),
                        RowSync::fit($db, $t, 'user_name', $vals[3]), RowSync::fit($db, $t, 'action', $vals[4]), $vals[5]]);
            } else {
                $this->db()->insert('INSERT INTO `audit` (`id`, `at`, `user_id`, `user_name`, `action`, `detail`) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `id`=`id`', $vals);
            }
        }
    }

    /** hr_simpan_settings — settings map + unknown top-level keys as `extra:<key>`. */
    private function replaceSettings(stdClass $data): void
    {
        $skip = [...array_keys(self::COLLECTIONS), ...self::NOT_EXTRA];
        $this->db()->delete('DELETE FROM `'.self::t('settings').'`');
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
        self::onCore()
            ? $this->db()->insert('INSERT INTO `hr_pengaturan` (`id`, `k`, `v`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `version`=`version`+(`v`<>VALUES(`v`)), `v`=VALUES(`v`)',
                [self::ulid(), RowSync::fit($this->db(), self::t('settings'), 'k', $k), self::enc($v)])
            : $this->db()->insert('INSERT INTO `settings` (`k`, `v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)', [$k, self::enc($v)]);
    }
}
