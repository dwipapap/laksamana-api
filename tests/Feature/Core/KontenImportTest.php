<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #55: konten tables in core, imported from the restored konten DB.
 * Row tables copy their indexed columns verbatim and keep the full row in
 * `data`; logs and settings documents follow the same idempotent sync.
 */

const KONTEN_TABLES = ['konten_users' => 'legacy_id', 'konten_brands' => 'legacy_id',
    'konten_campaigns' => 'legacy_id', 'konten_content' => 'legacy_id',
    'konten_prod_tasks' => 'legacy_id', 'konten_shootings' => 'legacy_id',
    'konten_assets' => 'legacy_id', 'konten_bank' => 'legacy_id',
    'konten_kols' => 'legacy_id', 'konten_visits' => 'legacy_id',
    'konten_ads' => 'legacy_id', 'konten_ad_funds' => 'legacy_id',
    'konten_notifs' => 'legacy_id', 'konten_logs' => 'legacy_id',
    'konten_pengaturan' => 'k'];

function kontenSnapshot(): array
{
    $out = [];
    foreach (KONTEN_TABLES as $table => $key) {
        $out[$table] = DB::connection('core')->table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function importKonten(): void
{
    test()->artisan('core:import', ['module' => 'konten'])->assertSuccessful();
}

it('imports konten twice into identical rows, mapping every legacy id 1:1', function () {
    $legacy = DB::connection('legacy_konten');

    importKonten();
    $first = kontenSnapshot();
    test()->artisan('core:import', ['module' => 'konten'])->assertSuccessful();
    expect(kontenSnapshot())->toBe($first);

    $keys = fn (string $t) => collect($first[$t])->pluck(KONTEN_TABLES[$t])->sort()->values()->all();
    $legacyKeys = fn (string $t) => $legacy->table($t)->pluck($t === 'settings' ? 'k' : 'id')->sort()->values()->all();

    expect($keys('konten_users'))->toBe($legacyKeys('users'))
        ->and($keys('konten_content'))->toBe($legacyKeys('content'))
        ->and($keys('konten_bank'))->toBe($legacyKeys('bank'))
        ->and($keys('konten_logs'))->toBe($legacyKeys('logs'))
        ->and($keys('konten_pengaturan'))->toBe($legacyKeys('settings'))
        ->and(collect($first['konten_content'])->every(fn ($r) => Str::isUlid($r['id']) && $r['version'] === 1))->toBeTrue();
});

it('keeps the bank collection although its screen is gone', function () {
    importKonten();

    expect(DB::connection('core')->table('konten_bank')->count())
        ->toBe(DB::connection('legacy_konten')->table('bank')->count())
        ->toBeGreaterThan(0);
});

it('follows legacy changes on a re-import: changed rows bump version, gone rows are deleted', function () {
    $legacy = DB::connection('legacy_konten');
    importKonten();

    $brand = $legacy->table('brands')->first();
    $data = json_decode($brand->data, true);
    $data['name'] = 'Berubah';
    $legacy->table('brands')->where('id', $brand->id)->update([
        'name' => 'Berubah',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $gone = $legacy->table('notifs')->value('id');
    $legacy->table('notifs')->where('id', $gone)->delete();

    test()->artisan('core:import', ['module' => 'konten'])->assertSuccessful();
    $core = DB::connection('core');
    $row = $core->table('konten_brands')->where('legacy_id', $brand->id)->first();
    expect($row->name)->toBe('Berubah')
        ->and($row->version)->toBe(2)
        ->and($core->table('konten_notifs')->where('legacy_id', $gone)->exists())->toBeFalse();
});
