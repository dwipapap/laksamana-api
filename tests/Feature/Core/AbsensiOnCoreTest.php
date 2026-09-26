<?php

use App\Modules\Absensi\Services\AbsensiService;
use Illuminate\Support\Facades\DB;

/*
 * #53: absensi served from core. Each test imports the restored absensi DB
 * and switches the Modul to core; the default (legacy) connection is the
 * rollback path, covered by the rest of the suite.
 *
 * One auth identity per test (the Sesi guard caches its user).
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'absensi'])->assertSuccessful();
    config(['laksamana.modules.absensi.connection' => 'core']);
});

function absCore(string $table, string $legacyId): ?array
{
    $row = DB::connection('core')->table($table)->where('legacy_id', $legacyId)->first();

    return $row ? (array) $row : null;
}

it('serves konteks from core with locations, setting and server time', function () {
    $res = $this->get('/absensi/api/api.php?action=konteks')->assertOk()->json();

    expect($res['ok'])->toBeTrue()
        ->and($res['data'])->toHaveKeys(['lokasi', 'setting', 'waktuServer']);
});

it('saves settings and locations to the core tables with legacy ids', function () {
    $svc = app(AbsensiService::class);

    $svc->saveSetting(['tanpaShiftBoleh' => true, 'hr' => []], 'uji');
    expect(absCore('abs_setting', '1')['version'])->toBeGreaterThanOrEqual(1)
        ->and(json_decode(absCore('abs_setting', '1')['data'], true)['tanpaShiftBoleh'])->toBeTrue();

    $id = $svc->saveLocation(['nama' => 'Kantor Core', 'lat' => -7.75, 'lng' => 110.42, 'radius' => 150, 'aktif' => 1], 'uji');
    $row = absCore('abs_lokasi', $id);
    expect($row['nama'])->toBe('Kantor Core')->and($row['version'])->toBe(1);

    expect($svc->locations(true))->toContain(['id' => $id, 'nama' => 'Kantor Core',
        'lat' => -7.75, 'lng' => 110.42, 'radius' => 150, 'aktif' => 1]);

    $svc->deleteLocation($id);
    expect(absCore('abs_lokasi', $id))->toBeNull();
});

it('registers, lists and deletes faces on core without descriptors', function () {
    $svc = app(AbsensiService::class);
    $desc = array_fill(0, 128, 0.5);

    expect($svc->saveFace('USER', 'u-core1', 'Core Satu', $desc, null, 'uji'))->toBeTrue();
    $row = absCore('abs_wajah', 'USER:u-core1');
    expect($row['nama'])->toBe('Core Satu')->and($row['version'])->toBe(1);

    $faces = $svc->listFaces();
    $mine = array_values(array_filter($faces, fn ($f) => $f['id'] === 'u-core1'));
    expect($mine)->toHaveCount(1)->and($mine[0])->not->toHaveKey('descriptor');

    expect($svc->faceRegistered('USER', 'u-core1'))->toBeTrue();

    $svc->deleteFace('USER', 'u-core1');
    expect(absCore('abs_wajah', 'USER:u-core1'))->toBeNull()
        ->and($svc->faceRegistered('USER', 'u-core1'))->toBeFalse();
});

it('records MASUK once and decides it from the core queue', function () {
    $svc = app(AbsensiService::class);
    // wajahWajib with an unenrolled face queues the punch (WAJAH_KOSONG).
    $svc->saveSetting(['tanpaShiftBoleh' => true, 'wajahWajib' => true, 'hr' => []], 'uji');

    $p = ['tipe' => 'USER', 'id' => 'u-core2', 'nama' => 'Core Dua', 'arah' => 'MASUK',
        'lat' => 0, 'lng' => 0, 'akurasi' => 0, 'descriptor' => null, 'foto' => null, 'alasan' => 'uji core'];
    $one = $svc->recordPunch($p);
    expect($one['duplikat'])->toBeFalse()->and($one['punch']['uid'])->toBe('u-core2');

    $again = $svc->recordPunch($p);
    expect($again['duplikat'])->toBeTrue()
        ->and($again['punch']['id'])->toBe($one['punch']['id']);

    $id = $one['punch']['id'];
    expect(absCore('abs_punch', $id)['status'])->toBe($one['punch']['status']);

    $queue = $svc->queue();
    expect(collect($queue)->pluck('id'))->toContain($id);

    expect($svc->decidePunch($id, 'VALID', 'ok', 'uji'))->toBeTrue();
    expect(absCore('abs_punch', $id)['status'])->toBe('VALID');
    expect($svc->decidePunch($id, 'DITOLAK', 'telat', 'uji'))->toBeFalse();
});

it('computes the recap from core punches', function () {
    $svc = app(AbsensiService::class);
    $svc->saveSetting(['tanpaShiftBoleh' => true, 'wajahWajib' => false, 'hr' => []], 'uji');

    $base = ['tipe' => 'USER', 'id' => 'u-core3', 'nama' => 'Core Tiga',
        'lat' => 0, 'lng' => 0, 'akurasi' => 0, 'descriptor' => null, 'foto' => null, 'alasan' => 'uji core'];
    $masuk = $svc->recordPunch($base + ['arah' => 'MASUK']);
    $tgl = $masuk['punch']['tgl'];

    $recap = $svc->recap($tgl, $tgl, 'u-core3', 'USER');
    expect($recap['dari'])->toBe($tgl)
        ->and($recap['hari'])->toHaveCount(1)
        ->and($recap['hari'][0]['masuk']['id'])->toBe($masuk['punch']['id']);
});

it('answers the legacy antrean and rekap from core for HR', function () {
    $sesi = legacySesi(officeUser('u-admin'));
    $this->legacyPost('/absensi/api/api.php', ['action' => 'antrean', 'sesi' => $sesi])->assertOk()->assertJsonPath('ok', true);

    $tgl = AbsensiService::tglWib((int) (microtime(true) * 1000));
    $this->get("/absensi/api/api.php?action=rekap&dari=$tgl&sampai=$tgl&sesi=$sesi")
        ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['dari', 'sampai', 'hari']]);
});

it('keeps the legacy stats keys on core', function () {
    $res = $this->get('/absensi/api/api.php?action=stats')->assertOk()->json();

    expect($res['data'])->toHaveKeys(['lokasi', 'wajah', 'punch', 'antre'])
        ->and($res['data']['punch'])->toBe(DB::connection('core')->table('abs_punch')->count());
});
