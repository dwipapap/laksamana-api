<?php

use Illuminate\Support\Facades\DB;

/*
 * #63: hr served from core. Each test reads the legacy answer where needed,
 * imports the restored hr DB and switches the Modul to core; the default
 * (legacy) connection is the rollback path, covered by the rest of the suite.
 */

function hrToCore(): void
{
    test()->artisan('core:import', ['module' => 'hr'])->assertSuccessful();
    config(['laksamana.modules.hr.connection' => 'core']);
}

function hrCorePost(array $body): array
{
    return test()->legacyPost('/hr-api-mysql/api.php', $body)->assertOk()->json();
}

it('serves the same getAll from core as from the legacy DB', function () {
    $legacy = $this->get('/hr-api-mysql/api.php?action=getAll')->assertOk()->json('data');
    hrToCore();
    $core = $this->get('/hr-api-mysql/api.php?action=getAll')->assertOk()->json('data');

    // Attendance moved from the extra:attendance setting into its tables
    // (#101): same months and day rows; days now sort by talentaId, date.
    $sortDays = function (array $att): array {
        foreach ($att as $m => $v) {
            usort($v['days'], fn ($a, $b) => [$a['talentaId'], $a['date']] <=> [$b['talentaId'], $b['date']]);
            ksort($v);
            $att[$m] = $v;
        }

        return $att;
    };
    expect($sortDays($core['attendance']))->toEqual($sortDays($legacy['attendance']));
    unset($core['attendance'], $legacy['attendance']);
    expect($core)->toBe($legacy);
});

it('writes saveAll to the hr tables of core and bumps the document rev', function () {
    hrToCore();
    $rev = (int) DB::connection('core')->table('hr_meta')->value('version');

    $r = hrCorePost(['action' => 'saveAll', 'baseRev' => $rev, 'by' => 'Tester', 'data' => [
        'employees' => [['id' => 'u-andry', 'name' => 'Andry'], ['id' => 'e_x', 'name' => 'Luar']],
        'kpiActuals' => ['d1' => ['2026-09' => ['i1' => 7]]],
        'attendance' => ['2026-09' => ['fileName' => 'a.xlsx', 'days' => [['talentaId' => 'T1', 'date' => '2026-09-01', 'empId' => 'e_x', 'code' => 'H']]]],
        'settings' => ['late' => 5],
    ]]);
    expect($r['data']['rev'])->toBe($rev + 1);

    $core = DB::connection('core');
    $month = $core->table('hr_attendance_months')->where('legacy_id', '2026-09')->first();
    expect($core->table('hr_employees')->pluck('legacy_id')->sort()->values()->all())->toBe(['e_x', 'u-andry'])
        ->and($core->table('hr_employees')->where('legacy_id', 'e_x')->value('user_id'))->toBeNull()
        ->and($core->table('hr_kpi_actuals')->where('legacy_id', 'd1|2026-09|i1')->value('nilai'))->toBe(7.0)
        ->and($core->table('hr_attendance_days')->where('legacy_id', '2026-09|T1|2026-09-01')->value('month_id'))->toBe($month->id)
        ->and($core->table('hr_pengaturan')->where('k', 'late')->value('v'))->toBe('5')
        ->and($core->table('hr_meta')->value('saved_by'))->toBe('Tester');

    // An unchanged row keeps its version; a changed one bumps it.
    $andry = $core->table('hr_employees')->where('legacy_id', 'u-andry')->value('version');
    hrCorePost(['action' => 'saveAll', 'baseRev' => $rev + 1, 'data' => [
        'employees' => [['id' => 'u-andry', 'name' => 'Andry'], ['id' => 'e_x', 'name' => 'Luar 2']],
    ]]);
    expect($core->table('hr_employees')->where('legacy_id', 'u-andry')->value('version'))->toBe($andry)
        ->and($core->table('hr_employees')->where('legacy_id', 'e_x')->value('version'))->toBe(2);
});

it('keeps the stale-rev conflict on core', function () {
    hrToCore();
    $meta = DB::connection('core')->table('hr_meta')->first();
    $r = hrCorePost(['action' => 'saveAll', 'baseRev' => 1, 'data' => ['employees' => []]]);
    expect($r)->toBe(['ok' => false, 'error' => 'conflict', 'savedBy' => $meta->saved_by, 'savedAt' => $meta->saved_at, 'rev' => (int) $meta->version])
        ->and(DB::connection('core')->table('hr_employees')->count())->toBeGreaterThan(0);
});

it('serves v1 writes from core under the same document rev', function () {
    hrToCore();
    $rev = (int) DB::connection('core')->table('hr_meta')->value('version');
    $token = loginAs(officeUser('u-rizkiarfan'));

    $id = $this->withToken($token)->postJson("/api/v1/hr/calendar?version=$rev", ['title' => 'Rapat', 'date' => '2026-10-01'])
        ->assertCreated()->assertJsonPath('meta.version', $rev + 1)->json('data.id');
    $row = DB::connection('core')->table('hr_calendar')->where('legacy_id', $id)->first();
    expect($row->judul)->toBe('Rapat')->and($row->tanggal)->toBe('2026-10-01');
});
