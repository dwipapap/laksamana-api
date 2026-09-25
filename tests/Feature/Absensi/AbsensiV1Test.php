<?php

use App\Modules\Absensi\Services\AbsensiService;

/*
 * /api/v1/absensi — the same rules as the legacy route (AbsensiService)
 * behind Sanctum + `module:absensi`. Failures use the v1 envelope:
 * 401 unauthenticated, 403 module_not_granted / forbidden, 422
 * validation_failed / rejected.
 *
 * One Sanctum identity per test: the guard caches its user on the shared
 * test app, so a second login would silently run as the first (production
 * boots a fresh app per request and never sees this). Cross-identity setup
 * goes through AbsensiService directly.
 */

function absV1(string $uid): string
{
    return loginAs(officeUser($uid));
}

function absV1Setelan(array $over = []): array
{
    return app(AbsensiService::class)->saveSetting($over + [
        'toleransiTelat' => 5, 'awalMenit' => 60, 'akhirMenit' => 180,
        'lemburMinMenit' => 15, 'wajahAmbang' => 0.45,
        'wajahWajib' => false, 'tanpaShiftBoleh' => true, 'hr' => [],
    ], 'uji');
}

function absV1Descriptor(float $v = 0.5): array
{
    return array_fill(0, 128, $v);
}

function absV1Today(): string
{
    return AbsensiService::tglWib((int) (microtime(true) * 1000));
}

it('opens a session like the legacy masuk', function () {
    $u = officeUser('u-dwipa');
    $r = test()->postJson('/api/v1/absensi/session', ['nama' => $u['name'], 'pin' => $u['pin']])->assertOk()->json();
    expect($r['data']['user']['id'])->toBe('u-dwipa');
    expect($r['data']['user']['token'])->not->toBe('');

    test()->postJson('/api/v1/absensi/session', ['nama' => $u['name'], 'pin' => 'salah'])
        ->assertStatus(422)->assertJsonPath('error.code', 'rejected');

    $luar = officeUser('u-yuzaalfarel');
    test()->postJson('/api/v1/absensi/session', ['nama' => $luar['name'], 'pin' => $luar['pin']])
        ->assertStatus(422)->assertJsonPath('error.message', 'Akun ini belum diberi akses modul Absensi. Minta admin membukanya di Office.');
});

it('gates the module and the login', function () {
    test()->getJson('/api/v1/absensi/context')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    $luar = absV1('u-yuzaalfarel');
    test()->getJson('/api/v1/absensi/context', ['Authorization' => 'Bearer '.$luar])
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('serves context for crew', function () {
    $t = absV1('u-dwipa');
    $r = test()->getJson('/api/v1/absensi/context', ['Authorization' => 'Bearer '.$t])->assertOk()->json();
    expect($r['data']['siapa'])->toMatchArray(['id' => 'u-dwipa', 'boleh' => 1, 'hr' => 0]);
    expect($r['data']['tgl'])->toBe(absV1Today());
});

it('punches in and out with the subject from the token', function () {
    absV1Setelan();
    app(AbsensiService::class)->saveFace('USER', 'u-dwipa', 'Dwipa', absV1Descriptor(), '', 'uji');
    $t = absV1('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    $masuk = test()->postJson('/api/v1/absensi/punches',
        ['arah' => 'MASUK', 'descriptor' => absV1Descriptor()] + [], $h)->assertCreated()->json();
    expect($masuk['data']['punch'])->toMatchArray(['uid' => 'u-dwipa', 'status' => 'VALID']);

    $lagi = test()->postJson('/api/v1/absensi/punches', ['arah' => 'MASUK', 'descriptor' => absV1Descriptor()], $h)->assertOk()->json();
    expect($lagi['data']['duplikat'])->toBeTrue();

    $pulang = test()->postJson('/api/v1/absensi/punches', ['arah' => 'PULANG', 'descriptor' => absV1Descriptor()], $h)->assertCreated()->json();
    expect($pulang['data']['punch']['arah'])->toBe('PULANG');

    test()->postJson('/api/v1/absensi/punches', ['arah' => 'DATANG'], $h)
        ->assertStatus(422)->assertJsonPath('error.message', 'Arah absen harus MASUK atau PULANG.');
});

it('forces crew recap to their own rows', function () {
    absV1Setelan();
    app(AbsensiService::class)->recordPunch(['tipe' => 'USER', 'id' => 'u-dwipa', 'nama' => 'Dwipa',
        'arah' => 'MASUK', 'lat' => 0, 'lng' => 0, 'akurasi' => 0,
        'descriptor' => null, 'foto' => '', 'alasan' => 'uji']);
    $t = absV1('u-dwipa');

    $r = test()->getJson('/api/v1/absensi/recap?from='.absV1Today().'&to='.absV1Today().'&user=u-admin',
        ['Authorization' => 'Bearer '.$t])->assertOk()->json();
    expect($r['data']['hari'])->not->toBe([]);
    foreach ($r['data']['hari'] as $h) {
        expect($h['uid'])->toBe('u-dwipa');
    }
});

it('queues and decides punches as HR', function () {
    absV1Setelan();
    app(AbsensiService::class)->saveLocation(
        ['nama' => 'Kantor', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => 1], 'uji');
    $hr = absV1('u-admin');
    $hh = ['Authorization' => 'Bearer '.$hr];

    $r = test()->postJson('/api/v1/absensi/punches',
        ['arah' => 'MASUK', 'lat' => 0, 'lng' => 0, 'alasan' => 'dinas'], $hh)->assertCreated()->json();
    $id = $r['data']['punch']['id'];
    expect($r['data']['punch'])->toMatchArray(['status' => 'MENUNGGU', 'sebab' => 'LUAR_AREA']);

    // reasonless out-of-policy punches are refused, not silently queued
    test()->postJson('/api/v1/absensi/punches', ['arah' => 'PULANG', 'lat' => 0, 'lng' => 0], $hh)
        ->assertStatus(422)->assertJsonPath('error.message', 'Absen ini di luar ketentuan, jadi harus disertai alasan untuk diajukan.');

    $q = test()->getJson('/api/v1/absensi/queue', $hh)->assertOk()->json();
    expect(collect($q['data'])->pluck('id')->all())->toContain($id);

    $d = test()->postJson("/api/v1/absensi/punches/$id/decision", ['status' => 'VALID'], $hh)->assertOk()->json();
    expect($d['data'])->toBe(['berubah' => 1]);
});

it('refuses crew queue reads and decisions', function () {
    $crew = absV1('u-dwipa');
    $ch = ['Authorization' => 'Bearer '.$crew];
    test()->getJson('/api/v1/absensi/queue', $ch)
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    test()->postJson('/api/v1/absensi/punches/x/decision', ['status' => 'VALID'], $ch)
        ->assertStatus(403);
});

it('enrols faces: own or HR', function () {
    absV1Setelan();
    $crew = absV1('u-dwipa');
    $ch = ['Authorization' => 'Bearer '.$crew];

    test()->postJson('/api/v1/absensi/faces',
        ['tipe' => 'USER', 'id' => 'u-dwipa', 'nama' => 'Dwipa', 'descriptor' => absV1Descriptor()], $ch)
        ->assertCreated()->assertJsonPath('data.tersimpan', true);

    test()->postJson('/api/v1/absensi/faces',
        ['tipe' => 'USER', 'id' => 'u-admin', 'nama' => 'Admin', 'descriptor' => absV1Descriptor()], $ch)
        ->assertStatus(403)->assertJsonPath('error.message', 'Hanya HR yang boleh mendaftarkan wajah orang lain.');

    test()->postJson('/api/v1/absensi/faces',
        ['tipe' => 'USER', 'id' => 'u-dwipa', 'nama' => 'Dwipa', 'descriptor' => [1.0]], $ch)
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('lists and deletes faces as HR', function () {
    app(AbsensiService::class)->saveFace('USER', 'u-andi', 'Andi', absV1Descriptor(0.7), '', 'uji');
    $hr = absV1('u-admin');
    $hh = ['Authorization' => 'Bearer '.$hr];

    $l = test()->getJson('/api/v1/absensi/faces', $hh)->assertOk()->json();
    $baris = collect($l['data'])->firstWhere('id', 'u-andi');
    expect($baris['ada'])->toBe(1)->and($baris)->not->toHaveKey('descriptor');

    test()->deleteJson('/api/v1/absensi/faces?tipe=USER&id=u-andi', [], $hh)
        ->assertOk()->assertJsonPath('data.hapus', 1);
});

it('refuses crew face listing', function () {
    $crew = absV1('u-dwipa');
    test()->getJson('/api/v1/absensi/faces', ['Authorization' => 'Bearer '.$crew])->assertStatus(403);
});

it('manages locations as HR and refuses crew', function () {
    $hr = absV1('u-admin');
    $hh = ['Authorization' => 'Bearer '.$hr];

    $c = test()->postJson('/api/v1/absensi/locations',
        ['nama' => 'Pos', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 5, 'aktif' => true], $hh)
        ->assertCreated()->json();
    $id = $c['data']['id'];

    $l = test()->getJson('/api/v1/absensi/locations', $hh)->assertOk()->json();
    expect(collect($l['data'])->firstWhere('id', $id)['radius'])->toBe(30);

    test()->deleteJson("/api/v1/absensi/locations/$id", [], $hh)->assertOk()->assertJsonPath('data.hapus', 1);
});

it('refuses crew location writes', function () {
    $crew = absV1('u-dwipa');
    test()->postJson('/api/v1/absensi/locations', ['nama' => 'X'], ['Authorization' => 'Bearer '.$crew])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('reads and saves settings with the HR gate', function () {
    $hr = absV1('u-admin');
    $hh = ['Authorization' => 'Bearer '.$hr];

    test()->getJson('/api/v1/absensi/settings', $hh)->assertOk()->assertJsonPath('data.toleransiTelat', 5);

    test()->putJson('/api/v1/absensi/settings', ['data' => ['toleransiTelat' => 9]], $hh)
        ->assertOk()->assertJsonPath('data.toleransiTelat', 9);
});

it('refuses crew settings writes', function () {
    $crew = absV1('u-dwipa');
    test()->putJson('/api/v1/absensi/settings', ['data' => ['toleransiTelat' => 1]], ['Authorization' => 'Bearer '.$crew])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
