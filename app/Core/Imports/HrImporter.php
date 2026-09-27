<?php

declare(strict_types=1);

namespace App\Core\Imports;

use App\Modules\Hr\Services\HrState;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * HR cutover import (#63): every record table verbatim (indexed columns + the
 * full `data` JSON), the append-only audit, the composite-key KPI actual and
 * monthly input maps, the attendance months/days, every settings document
 * and the whole-document rev into the hr_* tables of `core`. Mapping:
 * docs/db/hr.md.
 *
 * Attendance follows the live data (#101): a legacy DB WITH the attendance
 * tables is copied row for row and its settings verbatim; one WITHOUT them
 * (production) keeps the map in the `extra:attendance` setting, which is
 * exploded into hr_attendance_months/days exactly as HrState::writeAttendance
 * would store it, and not copied as a setting.
 *
 * Idempotent: rows are matched on `legacy_id` (or `k` for pengaturan),
 * unchanged rows are not touched, changed rows get `version + 1`, and rows
 * whose legacy source is gone are deleted. `hr_meta.version` is set to the
 * legacy rev.
 */
final class HrImporter implements Importer
{
    public function module(): string
    {
        return 'hr';
    }

    public function legacyConnections(): array
    {
        return ['legacy_hr'];
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
        $legacy = DB::connection('legacy_hr');
        $hasAttendance = (int) $legacy->selectOne(
            "SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('attendance_months','attendance_days')"
        )->c === 2;

        $rows = [];
        foreach ([...array_values(HrState::COLLECTIONS), 'audit'] as $table) {
            foreach ($legacy->table($table)->orderBy('id')->get() as $r) {
                $a = (array) $r;
                $id = (string) $a['id'];
                unset($a['id']);
                $rows["hr_$table"][$id] = $a;
            }
        }
        foreach ($legacy->table('kpi_actuals')->get() as $r) {
            $rows['hr_kpi_actuals']["$r->div_id|$r->bulan|$r->item_id"] = (array) $r;
        }
        foreach ($legacy->table('monthly_inputs')->get() as $r) {
            $rows['hr_monthly_inputs']["$r->emp_id|$r->bulan"] = (array) $r;
        }

        $settings = [];
        foreach ($legacy->table('settings')->orderBy('k')->get() as $r) {
            $settings[(string) $r->k] = ['v' => (string) $r->v];
        }
        [$months, $days] = $hasAttendance ? $this->attendanceTables($legacy) : $this->attendanceSetting($settings);
        $meta = $legacy->table('meta')->where('id', 1)->first();

        $count = 0;
        $this->core()->transaction(function () use ($rows, $settings, $months, $days, $meta, &$count): void {
            foreach ([...array_map(fn ($t) => "hr_$t", array_values(HrState::COLLECTIONS)), 'hr_audit', 'hr_kpi_actuals', 'hr_monthly_inputs'] as $table) {
                $this->sync($table, $rows[$table] ?? []);
                $count += count($rows[$table] ?? []);
            }
            // An employee is the Office User with the same legacy id, when one exists.
            $this->core()->update('UPDATE `hr_employees` e LEFT JOIN `user` u ON u.`legacy_id` = e.`legacy_id` SET e.`user_id` = u.`id`');

            $this->sync('hr_attendance_months', $months);
            $monthIds = $this->core()->table('hr_attendance_months')->pluck('id', 'legacy_id');
            foreach ($days as $k => $d) {
                $days[$k]['month_id'] = $monthIds[$d['bulan']];
            }
            $this->sync('hr_attendance_days', $days);
            $this->sync('hr_pengaturan', $settings, 'k');
            $count += count($months) + count($days) + count($settings);

            $this->core()->table('hr_meta')->where('legacy_id', '<>', 1)->delete();
            if ($meta) {
                $cols = ['version' => (int) $meta->rev, 'saved_at' => $meta->saved_at, 'saved_by' => $meta->saved_by, 'versi' => (int) $meta->versi];
                $this->core()->table('hr_meta')->where('legacy_id', 1)->exists()
                    ? $this->core()->table('hr_meta')->where('legacy_id', 1)->update($cols)
                    : $this->core()->table('hr_meta')->insert(['id' => strtolower((string) Str::ulid()), 'legacy_id' => 1, ...$cols]);
                $count++;
            } else {
                $this->core()->table('hr_meta')->delete();
            }
        });

        return $count;
    }

    /** Legacy attendance tables, row for row (orphan days are invisible to getAll and skipped). */
    private function attendanceTables(ConnectionInterface $legacy): array
    {
        $months = [];
        foreach ($legacy->table('attendance_months')->get() as $r) {
            $months[(string) $r->bulan] = (array) $r;
        }
        $days = [];
        foreach ($legacy->table('attendance_days')->get() as $r) {
            if (isset($months[(string) $r->bulan])) {
                $days["$r->bulan|$r->talenta_id|$r->tanggal"] = (array) $r;
            }
        }

        return [$months, $days];
    }

    /**
     * The `extra:attendance` setting exploded as HrState::writeAttendance
     * stores a month (summary without days/importedAt/importedBy; days
     * without talentaId/date/empId, skipped when either key is empty). The
     * setting itself is removed from $settings.
     *
     * @param  array<string,array{v:string}>  $settings
     */
    private function attendanceSetting(array &$settings): array
    {
        $map = isset($settings['extra:attendance']) ? json_decode($settings['extra:attendance']['v']) : null;
        unset($settings['extra:attendance']);
        $months = [];
        $days = [];
        if (! $map instanceof stdClass) {
            return [$months, $days];
        }
        foreach ($map as $bulan => $isi) {
            if (! $isi instanceof stdClass) {
                continue;
            }
            $bulan = (string) $bulan;
            $summary = clone $isi;
            unset($summary->days, $summary->importedAt, $summary->importedBy);
            $months[$bulan] = ['bulan' => $bulan, 'data' => HrState::enc($summary),
                'imported_at' => HrState::s($isi->importedAt ?? ''), 'imported_by' => HrState::s($isi->importedBy ?? '')];
            foreach (isset($isi->days) && is_array($isi->days) ? $isi->days : [] as $d) {
                if (! $d instanceof stdClass) {
                    continue;
                }
                $tid = HrState::s($d->talentaId ?? '');
                $tgl = HrState::s($d->date ?? '');
                if ($tid === '' || $tgl === '') {
                    continue;
                }
                $row = clone $d;
                unset($row->talentaId, $row->date, $row->empId);
                $days["$bulan|$tid|$tgl"] = ['bulan' => $bulan, 'talenta_id' => $tid, 'tanggal' => $tgl,
                    'emp_id' => HrState::s($d->empId ?? ''), 'data' => HrState::enc($row)];
            }
        }

        return [$months, $days];
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
        $existing = $db->table($table)->get()->keyBy($key);
        foreach ($rows as $k => $cols) {
            $k = (string) $k;
            $cur = $existing[$k] ?? null;
            if ($cur === null) {
                $db->table($table)->insert(['id' => strtolower((string) Str::ulid()), $key => $k, ...$cols, 'version' => 1]);

                continue;
            }
            $changed = [];
            foreach ($cols as $c => $v) {
                if (! self::same($cur->$c ?? null, $v)) {
                    $changed[$c] = $v;
                }
            }
            if ($changed !== []) {
                $changed['version'] = (int) $cur->version + 1;
                $db->table($table)->where($key, $k)->update($changed);
            }
        }
        $wanted = array_map('strval', array_keys($rows));
        $gone = $existing->keys()->map(fn ($k) => (string) $k)->diff($wanted);
        foreach ($gone->chunk(500) as $chunk) {
            $db->table($table)->whereIn($key, $chunk->values()->all())->delete();
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

        return (string) $stored === (string) $wanted;
    }
}
