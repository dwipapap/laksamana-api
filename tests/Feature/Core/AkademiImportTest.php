<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #57: akademi tables in core, imported from the restored akademi DB.
 * Row tables copy their indexed columns verbatim and keep the full row in
 * `data`; progress maps use composite legacy ids ("user|material",
 * "user|program|material", like jadwal_sel's "user|tgl").
 */

const AKADEMI_TABLES = ['akademi_users' => 'legacy_id', 'akademi_divisions' => 'legacy_id',
    'akademi_materials' => 'legacy_id', 'akademi_programs' => 'legacy_id',
    'akademi_progress' => 'legacy_id', 'akademi_prog_prog' => 'legacy_id',
    'akademi_activity' => 'legacy_id', 'akademi_pengaturan' => 'k'];

function akademiSnapshot(): array
{
    $out = [];
    foreach (AKADEMI_TABLES as $table => $key) {
        $out[$table] = DB::connection('core')->table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function importAkademi(): void
{
    test()->artisan('core:import', ['module' => 'akademi'])->assertSuccessful();
}

it('imports akademi twice into identical rows, mapping every legacy id 1:1', function () {
    $legacy = DB::connection('legacy_akademi');

    importAkademi();
    $first = akademiSnapshot();
    test()->artisan('core:import', ['module' => 'akademi'])->assertSuccessful();
    expect(akademiSnapshot())->toBe($first);

    $keys = fn (string $t) => collect($first[$t])->pluck(AKADEMI_TABLES[$t])->sort()->values()->all();

    expect($keys('akademi_users'))->toBe($legacy->table('users')->pluck('id')->sort()->values()->all())
        ->and($keys('akademi_materials'))->toBe($legacy->table('materials')->pluck('id')->sort()->values()->all())
        ->and($keys('akademi_progress'))->toBe(
            $legacy->table('progress')->get()->map(fn ($r) => "$r->user_id|$r->material_id")->sort()->values()->all())
        ->and($keys('akademi_prog_prog'))->toBe(
            $legacy->table('prog_prog')->get()->map(fn ($r) => "$r->user_id|$r->program_id|$r->material_id")->sort()->values()->all())
        ->and($keys('akademi_activity'))->toBe($legacy->table('activity')->pluck('id')->sort()->values()->all())
        ->and($keys('akademi_pengaturan'))->toBe($legacy->table('settings')->pluck('k')->sort()->values()->all())
        ->and(collect($first['akademi_users'])->every(fn ($r) => Str::isUlid($r['id']) && $r['version'] === 1))->toBeTrue();
});

it('follows legacy changes on a re-import: changed rows bump version, gone rows are deleted', function () {
    $legacy = DB::connection('legacy_akademi');
    importAkademi();

    $mat = $legacy->table('materials')->first();
    $data = json_decode($mat->data, true);
    $data['title'] = 'Berubah';
    $legacy->table('materials')->where('id', $mat->id)->update([
        'title' => 'Berubah',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $gone = $legacy->table('divisions')->value('id');
    $legacy->table('divisions')->where('id', $gone)->delete();

    test()->artisan('core:import', ['module' => 'akademi'])->assertSuccessful();
    $core = DB::connection('core');
    $row = $core->table('akademi_materials')->where('legacy_id', $mat->id)->first();
    expect($row->title)->toBe('Berubah')
        ->and($row->version)->toBe(2)
        ->and($core->table('akademi_divisions')->where('legacy_id', $gone)->exists())->toBeFalse();
});
