<?php

use App\Modules\Jadwal\Services\JadwalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #47: jadwal tables in core, imported from the restored jadwal DB.
 * `core:import account` must run first (user ULIDs); stale crew ids drop.
 */

const JADWAL_TABLES = ['jadwal_sel' => 'legacy_id', 'jadwal_pengajuan' => 'legacy_id',
    'jadwal_shift' => 'kode', 'jadwal_jabatan' => 'legacy_id', 'jadwal_shift_kru' => 'legacy_id',
    'jadwal_manajemen' => 'legacy_id', 'jadwal_pengaturan' => 'legacy_id'];

function jadwalSnapshot(): array
{
    $out = [];
    foreach (JADWAL_TABLES as $table => $key) {
        $out[$table] = DB::connection('core')->table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function importJadwal(): void
{
    test()->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    test()->artisan('core:import', ['module' => 'jadwal'])->assertSuccessful();
}

it('imports jadwal twice into identical rows, mapping every legacy id 1:1', function () {
    $legacy = DB::connection('legacy_jadwal');

    importJadwal();
    $first = jadwalSnapshot();
    test()->artisan('core:import', ['module' => 'jadwal'])->assertSuccessful();
    expect(jadwalSnapshot())->toBe($first);

    $selKeys = $legacy->table('jadwal_sel')->get()->map(fn ($r) => "$r->user_id|$r->tgl")->sort()->values()->all();
    $knownUsers = DB::connection('core')->table('user')->pluck('legacy_id')->all();
    $selKeys = array_values(array_filter($selKeys, fn ($k) => in_array(explode('|', $k)[0], $knownUsers, true)));
    $keys = fn (string $t) => collect($first[$t])->pluck(JADWAL_TABLES[$t])->sort()->values()->all();

    expect($keys('jadwal_sel'))->toBe(collect($selKeys)->sort()->values()->all())
        ->and($keys('jadwal_pengajuan'))->toBe($legacy->table('jadwal_pengajuan')->pluck('id')->sort()->values()->all())
        ->and($keys('jadwal_shift'))->toBe(collect(array_keys(json_decode($legacy->table('jadwal_setting')->where('id', 1)->value('data'), true)['shifts'] ?? []))->sort()->values()->all())
        ->and(collect($first['jadwal_sel'])->every(fn ($r) => Str::isUlid($r['id']) && $r['version'] === 1))->toBeTrue()
        ->and($first['jadwal_pengaturan'][0]['legacy_id'])->toBe('1');
});

it('rebuilds the setting blob from the normalised tables', function () {
    importJadwal();
    $jadwal = app(JadwalService::class);
    $data = json_decode(json_encode($jadwal->setting()), true);

    $blob = json_decode(DB::connection('legacy_jadwal')->table('jadwal_setting')->where('id', 1)->value('data'), true);
    expect($data['shifts'])->toBe($blob['shifts'])
        ->and($data['maksBeruntun'])->toBe($blob['maksBeruntun'])
        ->and($data['jedaMin'])->toBe($blob['jedaMin'])
        ->and($data['manajemen'])->toBe($blob['manajemen']);
});

it('follows legacy changes on a re-import: changed rows bump version, gone rows are deleted', function () {
    $legacy = DB::connection('legacy_jadwal');
    importJadwal();

    $cell = $legacy->table('jadwal_sel')->first();
    $legacy->table('jadwal_sel')->where('user_id', $cell->user_id)->where('tgl', $cell->tgl)->update(['catatan' => 'baru']);
    $gone = $legacy->table('jadwal_pengajuan')->value('id');
    $legacy->table('jadwal_pengajuan')->where('id', $gone)->delete();

    test()->artisan('core:import', ['module' => 'jadwal'])->assertSuccessful();
    $core = DB::connection('core');
    $row = $core->table('jadwal_sel')->where('legacy_id', "$cell->user_id|$cell->tgl")->first();
    expect($row->catatan)->toBe('baru')
        ->and($row->version)->toBe(2)
        ->and($core->table('jadwal_pengajuan')->where('legacy_id', $gone)->exists())->toBeFalse();
});

it('drops cells for Users that no longer exist instead of inventing them', function () {
    $legacy = DB::connection('legacy_jadwal');
    importJadwal();

    $legacy->table('jadwal_sel')->insert(['user_id' => 'u-ghost', 'tgl' => '2026-01-01', 'shift' => 'PAGI']);
    test()->artisan('core:import', ['module' => 'jadwal'])->assertSuccessful();

    expect(DB::connection('core')->table('jadwal_sel')->where('legacy_id', 'u-ghost|2026-01-01')->exists())->toBeFalse();
});
