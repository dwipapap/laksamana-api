<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #53: absensi tables in core, imported from the restored absensi DB
 * (dev dump). Faces keep their natural key in legacy_id; punches and
 * locations keep their generated ids the same way.
 */

const ABSENSI_TABLES = ['abs_lokasi' => 'legacy_id', 'abs_wajah' => 'legacy_id',
    'abs_punch' => 'legacy_id', 'abs_setting' => 'legacy_id'];

function absensiSnapshot(): array
{
    $out = [];
    foreach (ABSENSI_TABLES as $table => $key) {
        $out[$table] = DB::connection('core')->table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function importAbsensi(): void
{
    test()->artisan('core:import', ['module' => 'absensi'])->assertSuccessful();
}

it('imports absensi twice into identical rows, mapping every legacy id 1:1', function () {
    $legacy = DB::connection('legacy_absensi');

    importAbsensi();
    $first = absensiSnapshot();
    test()->artisan('core:import', ['module' => 'absensi'])->assertSuccessful();
    expect(absensiSnapshot())->toBe($first);

    $keys = fn (string $t) => collect($first[$t])->pluck('legacy_id')->sort()->values()->all();

    expect($keys('abs_lokasi'))->toBe($legacy->table('abs_lokasi')->pluck('id')->sort()->values()->all())
        ->and($keys('abs_wajah'))->toBe($legacy->table('abs_wajah')->pluck('subjek')->sort()->values()->all())
        ->and($keys('abs_punch'))->toBe($legacy->table('abs_punch')->pluck('id')->sort()->values()->all())
        ->and($keys('abs_setting'))->toBe(['1'])
        ->and(collect($first['abs_wajah'])->every(fn ($r) => Str::isUlid($r['id']) && $r['version'] === 1))->toBeTrue()
        ->and(DB::connection('core')->table('abs_setting')->where('legacy_id', '1')->value('data'))
        ->toBe($legacy->table('abs_setting')->where('id', 1)->value('data'));
});

it('follows legacy changes on a re-import: changed rows bump version, gone rows are deleted', function () {
    $legacy = DB::connection('legacy_absensi');
    importAbsensi();

    $legacy->table('abs_setting')->where('id', 1)->update(['data' => json_encode(['hr' => []], JSON_UNESCAPED_UNICODE)]);
    $gone = $legacy->table('abs_punch')->value('id');
    $legacy->table('abs_punch')->where('id', $gone)->delete();

    test()->artisan('core:import', ['module' => 'absensi'])->assertSuccessful();
    $core = DB::connection('core');
    expect($core->table('abs_setting')->where('legacy_id', '1')->value('version'))->toBe(2)
        ->and($core->table('abs_punch')->where('legacy_id', $gone)->exists())->toBeFalse();
});
