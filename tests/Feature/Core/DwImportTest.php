<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #51: dw tables in core, imported from the restored dw DB.
 * Workers, assignments and requests keep every column verbatim under their
 * legacy id; dw_login_gagal / dw_sesi are not imported (unused remnants).
 */

const DW_TABLES = ['dw_pekerja' => 'legacy_id', 'dw_ajuan' => 'legacy_id',
    'dw_permintaan' => 'legacy_id', 'dw_setting' => 'legacy_id'];

function dwSnapshot(): array
{
    $out = [];
    foreach (DW_TABLES as $table => $key) {
        $out[$table] = DB::connection('core')->table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function importDw(): void
{
    test()->artisan('core:import', ['module' => 'dw'])->assertSuccessful();
}

it('imports dw twice into identical rows, mapping every legacy id 1:1', function () {
    $legacy = DB::connection('legacy_dw');

    importDw();
    $first = dwSnapshot();
    test()->artisan('core:import', ['module' => 'dw'])->assertSuccessful();
    expect(dwSnapshot())->toBe($first);

    $keys = fn (string $t) => collect($first[$t])->pluck('legacy_id')->sort()->values()->all();

    expect($keys('dw_pekerja'))->toBe($legacy->table('dw_pekerja')->pluck('id')->sort()->values()->all())
        ->and($keys('dw_ajuan'))->toBe($legacy->table('dw_ajuan')->pluck('id')->sort()->values()->all())
        ->and($keys('dw_permintaan'))->toBe($legacy->table('dw_permintaan')->pluck('id')->sort()->values()->all())
        ->and($keys('dw_setting'))->toBe(['1'])
        ->and(collect($first['dw_pekerja'])->every(fn ($r) => Str::isUlid($r['id']) && $r['version'] === 1))->toBeTrue()
        ->and(DB::connection('core')->table('dw_setting')->where('legacy_id', '1')->value('data'))
        ->toBe($legacy->table('dw_setting')->where('id', 1)->value('data'));
});

it('keeps soft links and untouched columns verbatim', function () {
    importDw();
    $core = DB::connection('core');

    $ajuan = $core->table('dw_ajuan')->first();
    expect($core->table('dw_pekerja')->where('legacy_id', $ajuan->dw_id)->exists()
        || $ajuan->dw_id !== '')->toBeTrue();

    $pekerja = $core->table('dw_pekerja')->first();
    $legacy = DB::connection('legacy_dw')->table('dw_pekerja')->where('id', $pekerja->legacy_id)->first();
    expect($pekerja->no_hp)->toBe($legacy->no_hp)
        ->and($pekerja->divisi)->toBe($legacy->divisi)
        ->and($pekerja->bayar_jenis)->toBe($legacy->bayar_jenis);
});

it('follows legacy changes on a re-import: changed rows bump version, gone rows are deleted', function () {
    $legacy = DB::connection('legacy_dw');
    importDw();

    $w = $legacy->table('dw_pekerja')->first();
    $legacy->table('dw_pekerja')->where('id', $w->id)->update(['catatan' => 'baru']);
    $gone = $legacy->table('dw_permintaan')->value('id');
    $legacy->table('dw_permintaan')->where('id', $gone)->delete();

    test()->artisan('core:import', ['module' => 'dw'])->assertSuccessful();
    $core = DB::connection('core');
    $row = $core->table('dw_pekerja')->where('legacy_id', $w->id)->first();
    expect($row->catatan)->toBe('baru')
        ->and($row->version)->toBe(2)
        ->and($core->table('dw_permintaan')->where('legacy_id', $gone)->exists())->toBeFalse();
});
