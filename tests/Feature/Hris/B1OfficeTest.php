<?php

use App\Modules\Hris\Models\HrisLokasi;
use App\Modules\Hris\Services\HrisSettingsService;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

/*
 * HR office surface: locations CRUD, settings GET/PUT, and the one-time
 * import of the legacy absensi locations. HR-only; crew get 403.
 */

it('manages locations as HR', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $id = test()->postJson('/api/v1/hris/locations',
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 5, 'aktif' => true], $h)
        ->assertCreated()->json('data.id');
    // Radius below 30 m is clamped, like the legacy module.
    expect(HrisLokasi::query()->find($id)->radius_m)->toBe(30);

    test()->patchJson("/api/v1/hris/locations/{$id}", ['nama' => 'Outlet', 'radius' => 150], $h)->assertCreated();
    expect(HrisLokasi::query()->find($id)->radius_m)->toBe(150);

    $list = test()->getJson('/api/v1/hris/locations', $h)->assertOk()->json('data');
    expect(array_column($list, 'id'))->toContain($id);

    test()->deleteJson("/api/v1/hris/locations/{$id}", [], $h)->assertOk()->assertJsonPath('data.hapus', 1);
    expect(HrisLokasi::query()->find($id))->toBeNull();
});

it('keeps crew out of location management', function () {
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/locations', ['nama' => 'X'], $h)->assertStatus(403);
    test()->deleteJson('/api/v1/hris/locations/x', [], $h)->assertStatus(403);
    test()->postJson('/api/v1/hris/admin/import-locations', [], $h)->assertStatus(403);
    test()->getJson('/api/v1/hris/locations', $h)->assertOk(); // reading is open
});

it('reads and saves settings as HR', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $r = test()->getJson('/api/v1/hris/settings', $h)->assertOk()->json('data');
    expect($r)->toMatchArray(['awalMenit' => 180, 'akurasiMaks' => 100,
        'pulangLewatMenit' => 180, 'tanpaShiftBoleh' => false]);
    expect($r)->not->toHaveKey('wajahWajib');
    expect($r)->not->toHaveKey('akhirMenit');
    expect($r['durasiKerja'])->toMatchArray(['default' => 540, 'orang' => []]);

    // The old PWA still sends wajahWajib and akhirMenit: accepted, then ignored.
    $r = test()->putJson('/api/v1/hris/settings', ['data' => ['awalMenit' => 90, 'wajahWajib' => true, 'akhirMenit' => 180]], $h)
        ->assertOk()->json('data');
    expect($r)->toMatchArray(['awalMenit' => 90]);
    expect($r)->not->toHaveKey('wajahWajib');
    expect($r)->not->toHaveKey('akhirMenit');

    // Unknown keys and out-of-range values are refused.
    test()->putJson('/api/v1/hris/settings', ['data' => ['bonus' => 1]], $h)->assertStatus(422);
    test()->putJson('/api/v1/hris/settings', ['data' => ['awalMenit' => 9999]], $h)->assertStatus(422);
    test()->putJson('/api/v1/hris/settings', ['data' => ['tanpaShiftBoleh' => 'ya']], $h)->assertStatus(422);
    test()->putJson('/api/v1/hris/settings', ['data' => ['akurasiMaks' => 9]], $h)->assertStatus(422);
    test()->putJson('/api/v1/hris/settings', ['data' => ['akurasiMaks' => 1001]], $h)->assertStatus(422);
    test()->putJson('/api/v1/hris/settings', ['data' => ['pulangLewatMenit' => 721]], $h)->assertStatus(422);
    test()->putJson('/api/v1/hris/settings', ['data' => ['durasiKerja' => ['default' => 59, 'orang' => []]]], $h)
        ->assertStatus(422);
    test()->putJson('/api/v1/hris/settings', ['data' => ['durasiKerja' => ['default' => 540, 'orang' => ['u-x' => 30]]]], $h)
        ->assertStatus(422);

    $r = test()->putJson('/api/v1/hris/settings', ['data' => [
        'akurasiMaks' => 1000, 'pulangLewatMenit' => 0,
        'durasiKerja' => ['default' => 480, 'orang' => ['u-dwipa' => 600]],
    ]], $h)->assertOk()->json('data');
    expect($r)->toMatchArray(['akurasiMaks' => 1000, 'pulangLewatMenit' => 0]);
    expect($r['durasiKerja'])->toMatchArray(['default' => 480, 'orang' => ['u-dwipa' => 600]]);
});

it('keeps crew out of settings writes', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->getJson('/api/v1/hris/settings', $h)->assertOk();
    test()->putJson('/api/v1/hris/settings', ['data' => ['awalMenit' => 90]], $h)->assertStatus(403);
});

it('imports legacy absensi locations once, idempotently', function () {
    if (! Schema::connection('legacy_absensi')->hasTable('abs_lokasi')) {
        $this->markTestSkipped('no absensi dump restored in this environment');
    }
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $satu = test()->postJson('/api/v1/hris/admin/import-locations', [], $h)->assertOk()->json('data');
    $dua = test()->postJson('/api/v1/hris/admin/import-locations', [], $h)->assertOk()->json('data');

    expect($dua['dibuat'])->toBe(0);
    expect(HrisLokasi::query()->count())->toBe($satu['dibuat'] + $satu['diperbarui']);
    expect(app(HrisSettingsService::class)->get('awalMenit'))->toBe(180);
});

it('serves the roster name picker to HR and heads, never crew', function () {
    hrisMakeHr('u-admin');
    $ht = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$ht];

    $r = test()->getJson('/api/v1/hris/pengguna', $h)->assertOk()->json('data');
    expect($r)->toHaveKey('pengguna');
    expect(count($r['pengguna']))->toBeGreaterThan(0);
    foreach ($r['pengguna'] as $p) {
        expect($p)->toHaveKeys(['id', 'nama', 'divisi']);
        expect($p['id'])->not->toBe('');
        expect($p['nama'])->not->toBe('');
    }
    $nama = array_column($r['pengguna'], 'nama');
    $urut = $nama;
    sort($urut);
    expect($nama)->toBe($urut);
});

it('keeps crew out of the roster name picker', function () {
    $t = hrisToken('u-dwipa');
    test()->getJson('/api/v1/hris/pengguna', ['Authorization' => 'Bearer '.$t])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
