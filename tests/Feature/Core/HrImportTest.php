<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #63: hr tables in core, imported from the restored hr DB. Record tables
 * copy their indexed columns verbatim and keep the full row in `data`; the
 * KPI/monthly maps use composite legacy ids; production keeps attendance in
 * the `extra:attendance` setting (#101), which the import explodes into the
 * attendance tables.
 */

const HR_TABLES = ['hr_divisions' => 'legacy_id', 'hr_employees' => 'legacy_id', 'hr_okrs' => 'legacy_id',
    'hr_audit' => 'legacy_id', 'hr_kpi_actuals' => 'legacy_id', 'hr_monthly_inputs' => 'legacy_id',
    'hr_attendance_months' => 'legacy_id', 'hr_attendance_days' => 'legacy_id', 'hr_pengaturan' => 'k', 'hr_meta' => 'legacy_id'];

function hrSnapshot(): array
{
    $out = [];
    foreach (HR_TABLES as $table => $key) {
        $out[$table] = DB::connection('core')->table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function importHr(): void
{
    test()->artisan('core:import', ['module' => 'hr'])->assertSuccessful();
}

it('imports hr twice into identical rows, mapping every legacy id 1:1', function () {
    $legacy = DB::connection('legacy_hr');

    importHr();
    $first = hrSnapshot();
    importHr();
    expect(hrSnapshot())->toBe($first);

    $keys = fn (string $t) => collect($first[$t])->pluck(HR_TABLES[$t])->sort()->values()->all();
    expect($keys('hr_employees'))->toBe($legacy->table('employees')->pluck('id')->sort()->values()->all())
        ->and($keys('hr_audit'))->toBe($legacy->table('audit')->pluck('id')->sort()->values()->all())
        ->and($keys('hr_kpi_actuals'))->toBe(
            $legacy->table('kpi_actuals')->get()->map(fn ($r) => "$r->div_id|$r->bulan|$r->item_id")->sort()->values()->all())
        ->and(collect($first['hr_employees'])->every(fn ($r) => Str::isUlid($r['id']) && $r['version'] === 1))->toBeTrue()
        ->and($first['hr_meta'][0]['version'])->toBe((int) $legacy->table('meta')->value('rev'));
});

it('explodes the extra:attendance setting into month and day rows (#101)', function () {
    $legacy = DB::connection('legacy_hr');
    $setting = $legacy->table('settings')->where('k', 'extra:attendance')->value('v');
    expect($setting)->not->toBeNull();
    $map = json_decode($setting, true);

    importHr();
    $core = DB::connection('core');
    $days = array_sum(array_map(fn ($m) => count($m['days'] ?? []), $map));
    expect($core->table('hr_pengaturan')->where('k', 'extra:attendance')->exists())->toBeFalse()
        ->and($core->table('hr_pengaturan')->count())->toBe($legacy->table('settings')->count() - 1)
        ->and($core->table('hr_attendance_months')->pluck('legacy_id')->sort()->values()->all())->toBe(collect(array_keys($map))->sort()->values()->all())
        ->and($core->table('hr_attendance_days')->count())->toBe($days);

    $month = array_key_first($map);
    $d = $map[$month]['days'][0];
    $row = $core->table('hr_attendance_days')->where('legacy_id', "$month|{$d['talentaId']}|{$d['date']}")->first();
    unset($d['talentaId'], $d['date'], $d['empId']);
    expect(json_decode($row->data, true))->toBe($d)
        ->and($row->month_id)->toBe($core->table('hr_attendance_months')->where('legacy_id', $month)->value('id'));
});

it('links employees to the Office User with the same legacy id', function () {
    test()->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    importHr();
    $core = DB::connection('core');
    $linked = $core->table('hr_employees as e')->join('user as u', 'u.id', '=', 'e.user_id')->where('u.legacy_id', DB::raw('e.legacy_id'))->count();
    $users = $core->table('hr_employees')->whereIn('legacy_id', $core->table('user')->pluck('legacy_id'))->count();
    expect($users)->toBeGreaterThan(0)->and($linked)->toBe($users);
});

it('follows legacy changes on a re-import: changed rows bump version, gone rows are deleted', function () {
    $legacy = DB::connection('legacy_hr');
    importHr();

    $div = $legacy->table('divisions')->first();
    $data = json_decode($div->data, true);
    $data['name'] = 'Berubah';
    $legacy->table('divisions')->where('id', $div->id)->update(['nama' => 'Berubah', 'data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    $gone = $legacy->table('audit')->value('id');
    $legacy->table('audit')->where('id', $gone)->delete();
    $legacy->table('meta')->where('id', 1)->update(['rev' => 999]);

    importHr();
    $core = DB::connection('core');
    $row = $core->table('hr_divisions')->where('legacy_id', $div->id)->first();
    expect($row->nama)->toBe('Berubah')
        ->and($row->version)->toBe(2)
        ->and($core->table('hr_audit')->where('legacy_id', $gone)->exists())->toBeFalse()
        ->and($core->table('hr_meta')->value('version'))->toBe(999);
});
