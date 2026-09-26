<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #49: marketing tables in core, imported from the restored marketing DB.
 * Row tables copy their indexed columns verbatim and keep the full row in
 * `data`; vip/designreqs move from `settings` JSON into child tables.
 */

const MARKETING_TABLES = ['marketing_klien' => 'legacy_id', 'marketing_acara' => 'legacy_id',
    'marketing_tindak_lanjut' => 'legacy_id', 'marketing_persetujuan' => 'legacy_id',
    'marketing_pengguna' => 'legacy_id', 'marketing_staf' => 'legacy_id',
    'marketing_template_tugas' => 'legacy_id', 'marketing_kategori_tugas' => 'legacy_id',
    'marketing_kategori' => 'legacy_id', 'marketing_notifikasi' => 'legacy_id',
    'marketing_aktivitas' => 'legacy_id', 'marketing_vip' => 'legacy_id',
    'marketing_permintaan_desain' => 'legacy_id', 'marketing_pengaturan' => 'k'];

function marketingSnapshot(): array
{
    $out = [];
    foreach (MARKETING_TABLES as $table => $key) {
        $out[$table] = DB::connection('core')->table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all();
    }

    return $out;
}

function importMarketing(): void
{
    test()->artisan('core:import', ['module' => 'marketing'])->assertSuccessful();
}

it('imports marketing twice into identical rows, mapping every legacy id 1:1', function () {
    $legacy = DB::connection('legacy_marketing');

    importMarketing();
    $first = marketingSnapshot();
    test()->artisan('core:import', ['module' => 'marketing'])->assertSuccessful();
    expect(marketingSnapshot())->toBe($first);

    $keys = fn (string $t) => collect($first[$t])->pluck(MARKETING_TABLES[$t])->sort()->values()->all();
    $legacyKeys = fn (string $t) => $legacy->table($t)->pluck('id')->sort()->values()->all();

    expect($keys('marketing_klien'))->toBe($legacyKeys('clients'))
        ->and($keys('marketing_acara'))->toBe($legacyKeys('events'))
        ->and($keys('marketing_tindak_lanjut'))->toBe($legacyKeys('followups'))
        ->and($keys('marketing_persetujuan'))->toBe($legacyKeys('approvals'))
        ->and($keys('marketing_pengguna'))->toBe($legacyKeys('users'))
        ->and($keys('marketing_staf'))->toBe($legacyKeys('staff'))
        ->and($keys('marketing_template_tugas'))->toBe($legacyKeys('task_templates'))
        ->and($keys('marketing_aktivitas'))->toBe($legacyKeys('activities'))
        ->and(collect($first['marketing_klien'])->every(fn ($r) => Str::isUlid($r['id']) && $r['version'] === 1))->toBeTrue();
});

it('moves vip and designreqs from settings JSON into child tables, in list order', function () {
    $legacy = DB::connection('legacy_marketing');
    importMarketing();

    $vip = collect(json_decode($legacy->table('settings')->where('k', 'extra:vip')->value('v'), true));
    $design = collect(json_decode($legacy->table('settings')->where('k', 'extra:designreqs')->value('v'), true));
    $core = DB::connection('core');

    expect($core->table('marketing_vip')->orderBy('urutan')->pluck('legacy_id')->all())
        ->toBe($vip->pluck('id')->all())
        ->and($core->table('marketing_permintaan_desain')->orderBy('urutan')->pluck('legacy_id')->all())
        ->toBe($design->pluck('id')->all())
        ->and($core->table('marketing_pengaturan')->where('k', 'extra:vip')->exists())->toBeFalse()
        ->and($core->table('marketing_pengaturan')->where('k', 'extra:designreqs')->exists())->toBeFalse()
        ->and($core->table('marketing_pengaturan')->where('k', 'settings')->exists())->toBeTrue();
});

it('keeps the indexed columns and the verbatim row in sync', function () {
    importMarketing();
    $core = DB::connection('core');

    $row = $core->table('marketing_acara')->first();
    $data = json_decode($row->data, true);
    expect($row->legacy_id)->toBe($data['id'])
        ->and($row->nama)->toBe($data['nama'] ?? null)
        ->and($row->tanggal)->toBe($data['tanggal'] ?? null)
        ->and((int) $row->updated_at)->toBe((int) ($data['updatedAt'] ?? 0));

    $vip = $core->table('marketing_vip')->first();
    $vdata = json_decode($vip->data, true);
    expect($vip->legacy_id)->toBe($vdata['id'])
        ->and($vip->tanggal)->toBe($vdata['tanggal'] ?? null);
});

it('follows legacy changes on a re-import: changed rows bump version, gone rows are deleted', function () {
    $legacy = DB::connection('legacy_marketing');
    importMarketing();

    $client = $legacy->table('clients')->first();
    $data = json_decode($client->data, true);
    $data['nama'] = 'Berubah';
    $legacy->table('clients')->where('id', $client->id)->update([
        'nama' => 'Berubah',
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $gone = $legacy->table('notifs')->value('id');
    $legacy->table('notifs')->where('id', $gone)->delete();

    test()->artisan('core:import', ['module' => 'marketing'])->assertSuccessful();
    $core = DB::connection('core');
    $row = $core->table('marketing_klien')->where('legacy_id', $client->id)->first();
    expect($row->nama)->toBe('Berubah')
        ->and($row->version)->toBe(2)
        ->and($core->table('marketing_notifikasi')->where('legacy_id', $gone)->exists())->toBeFalse();
});

it('drops settings-held rows without ids instead of inventing them', function () {
    $legacy = DB::connection('legacy_marketing');
    importMarketing();

    $vip = collect(json_decode($legacy->table('settings')->where('k', 'extra:vip')->value('v'), true));
    $vip->push(['nama' => 'tanpa id', 'updatedAt' => 1790100000000]);
    $legacy->table('settings')->where('k', 'extra:vip')->update(['v' => json_encode($vip->all())]);
    test()->artisan('core:import', ['module' => 'marketing'])->assertSuccessful();

    expect(DB::connection('core')->table('marketing_vip')->count())->toBe($vip->count() - 1);
});
