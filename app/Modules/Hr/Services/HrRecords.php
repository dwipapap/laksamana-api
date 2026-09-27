<?php

namespace App\Modules\Hr\Services;

use InvalidArgumentException;
use stdClass;

/**
 * Granular writes for /api/v1/hr on top of the SAME whole-document revision
 * that legacy saveAll uses: every write needs the current `meta.rev`
 * (If-Match), runs under SELECT … FOR UPDATE on meta, and bumps rev +
 * saved_by (the session user). So an old laksamana-office tab that loaded
 * before a v1 write gets its conflict modal instead of overwriting it.
 *
 * A write result is ['ok'=>true,'rev'=>N] or the legacy conflict array
 * ['ok'=>false,'error'=>'conflict','savedBy','savedAt','rev'].
 */
class HrRecords
{
    /** v1 resource => app collection key */
    public const RESOURCES = [
        'divisions' => 'divisions', 'employees' => 'employees', 'kpi-templates' => 'kpiTemplates',
        'okrs' => 'okrs', 'reviews' => 'reviews', 'competencies' => 'competencies', 'trainings' => 'trainings',
        'training-records' => 'trainingRecords', 'coachings' => 'coachings', 'rewards' => 'rewards',
        'badges' => 'badges', 'violations' => 'violations', 'feedbacks' => 'feedbacks',
        'career-paths' => 'careerPaths', 'successions' => 'successions', 'moods' => 'moods',
        'suggestions' => 'suggestions', 'calendar' => 'calendar',
    ];

    public function __construct(private readonly HrState $state) {}

    public static function table(string $resource): string
    {
        if (! isset(self::RESOURCES[$resource])) {
            throw new InvalidArgumentException("unknown resource $resource");
        }

        return HrState::COLLECTIONS[self::RESOURCES[$resource]];
    }

    public function rev(): int
    {
        return (int) ($this->state->meta()?->rev ?? 0);
    }

    public function find(string $resource, string $id): ?stdClass
    {
        $r = $this->state->db()->selectOne('SELECT `data` FROM `'.HrState::t(self::table($resource)).'` WHERE `'.HrState::idCol().'` = ?', [$id]);
        $o = $r ? json_decode($r->data) : null;

        return is_object($o) ? $o : null;
    }

    /** Upsert one record (create or replace). $mustExist: null = either, true = update, false = create. */
    public function put(string $resource, stdClass $rec, int $baseRev, string $by, ?bool $mustExist): array
    {
        $table = self::table($resource);

        return $this->state->underRev($baseRev, function () use ($resource, $table, $rec, $by, $mustExist) {
            $exists = $this->find($resource, (string) $rec->id) !== null;
            if ($mustExist === true && ! $exists) {
                throw new HrMiss('not_found');
            }
            if ($mustExist === false && $exists) {
                throw new HrMiss('exists');
            }
            $this->state->upsert($table, $rec);

            return [$by, null];
        });
    }

    public function delete(string $resource, string $id, int $baseRev, string $by): array
    {
        $table = self::table($resource);

        return $this->state->underRev($baseRev, function () use ($table, $id, $by) {
            if ($this->state->db()->delete('DELETE FROM `'.HrState::t($table).'` WHERE `'.HrState::idCol().'` = ?', [$id]) === 0) {
                throw new HrMiss('not_found');
            }

            return [$by, null];
        });
    }

    /** One KPI actual cell; null/non-numeric clears it (deletes the row). */
    public function putKpiActual(string $divId, string $month, string $itemId, mixed $value, int $baseRev, string $by): array
    {
        return $this->state->underRev($baseRev, function () use ($divId, $month, $itemId, $value, $by) {
            if (is_numeric($value)) {
                $this->state->putKpiActual($divId, $month, $itemId, $value);
            } else {
                $this->state->db()->delete('DELETE FROM `'.HrState::t('kpi_actuals').'` WHERE `div_id`=? AND `bulan`=? AND `item_id`=?', [$divId, $month, $itemId]);
            }

            return [$by, null];
        });
    }

    /** One monthly input document; null deletes it. */
    public function putMonthly(string $empId, string $month, mixed $value, int $baseRev, string $by): array
    {
        return $this->state->underRev($baseRev, function () use ($empId, $month, $value, $by) {
            if ($value === null) {
                $this->state->db()->delete('DELETE FROM `'.HrState::t('monthly_inputs').'` WHERE `emp_id`=? AND `bulan`=?', [$empId, $month]);
            } else {
                $this->state->putMonthly($empId, $month, $value);
            }

            return [$by, null];
        });
    }

    /** One settings key; null deletes it. */
    public function putSetting(string $key, mixed $value, int $baseRev, string $by): array
    {
        return $this->state->underRev($baseRev, function () use ($key, $value, $by) {
            if ($value === null) {
                $this->state->db()->delete('DELETE FROM `'.HrState::t('settings').'` WHERE `k`=?', [$key]);
            } else {
                $this->state->putSetting($key, $value);
            }

            return [$by, null];
        });
    }

    /** One attendance month ({fileName, importedAt, importedBy, unmatched, days[]}); null deletes it. */
    public function putAttendanceMonth(string $month, ?stdClass $value, int $baseRev, string $by): array
    {
        return $this->state->underRev($baseRev, function () use ($month, $value, $by) {
            if (! $this->state->attendanceTables()) {
                // production without the tables keeps the whole map in `extra:attendance`
                $map = $this->state->attendance();
                if ($value === null) {
                    unset($map->$month);
                } else {
                    $map->$month = $value;
                }
                $this->state->putSetting('extra:attendance', $map);
            } elseif ($value === null) {
                $this->state->db()->delete('DELETE FROM `'.HrState::t('attendance_months').'` WHERE `bulan`=?', [$month]);
                $this->state->db()->delete('DELETE FROM `'.HrState::t('attendance_days').'` WHERE `bulan`=?', [$month]);
            } else {
                $this->state->writeAttendance((object) [$month => $value], false);
            }

            return [$by, null];
        });
    }

    /** Append audit entries (existing ids are never touched). Does not bump rev. */
    public function appendAudit(array $entries): void
    {
        $this->state->appendAudit($entries);
    }
}
