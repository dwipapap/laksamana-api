<?php

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;

/*
 * The decision queue: HR decides with the `berubah` version they saw; a
 * stale version is a 409, and deciding twice is a 409. One HTTP identity
 * per test; queued rows are planted through the service.
 */

function hrisQueued(string $uid, string $alasan = 'LUAR_AREA'): HrisKetukan
{
    hrisSettings(['tanpaShiftBoleh' => true]);
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);
    $out = app(HrisAttendanceService::class)->recordPunch(['id' => $uid, 'name' => $uid],
        ['arah' => 'MASUK', 'lat' => -6.0, 'lng' => 107.5, 'alasan' => 'dinas luar'],
        hrisMs(2026, 10, 20, 9, 0));
    expect($out['punch']['status'])->toBe('MENUNGGU');

    return HrisKetukan::query()->find($out['punch']['id']);
}

it('lists the queue longest-waiting first as HR', function () {
    hrisMakeHr('u-admin');
    hrisQueued('u-dwipa');
    $t = hrisToken('u-admin');

    $r = test()->getJson('/api/v1/hris/queue', ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    expect($r)->not->toBe([]);
    expect($r[0])->toHaveKeys(['id', 'user_id', 'status', 'alasan_antrean', 'berubah']);
    foreach ($r as $row) {
        expect($row['status'])->toBe('MENUNGGU');
    }
});

it('decides a queued punch as HR', function () {
    hrisMakeHr('u-admin');
    $row = hrisQueued('u-dwipa');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson("/api/v1/hris/punches/{$row->id}/decision",
        ['status' => 'VALID', 'nota' => 'ok', 'berubah' => (int) $row->version], $h)
        ->assertOk()->assertJsonPath('data.berubah', 1);

    expect(HrisKetukan::query()->find($row->id)->status)->toBe('VALID');
});

it('answers 409 on a stale berubah and on deciding twice', function () {
    hrisMakeHr('u-admin');
    $row = hrisQueued('u-dwipa');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];
    $url = "/api/v1/hris/punches/{$row->id}/decision";

    test()->postJson($url, ['status' => 'VALID', 'berubah' => (int) $row->version + 5], $h)
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');

    test()->postJson($url, ['status' => 'DITOLAK', 'berubah' => (int) $row->version], $h)->assertOk();
    test()->postJson($url, ['status' => 'VALID', 'berubah' => (int) $row->version + 1], $h)
        ->assertStatus(409)->assertJsonPath('error.code', 'already_decided');
});

it('refuses unknown decisions and unknown punches', function () {
    hrisMakeHr('u-admin');
    $row = hrisQueued('u-dwipa');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson("/api/v1/hris/punches/{$row->id}/decision", ['status' => 'OKE', 'berubah' => 1], $h)
        ->assertStatus(422);
    test()->postJson('/api/v1/hris/punches/01j0000000000000000000000/decision',
        ['status' => 'VALID', 'berubah' => 1], $h)->assertStatus(404);
});

it('keeps crew out of the queue and the decisions', function () {
    hrisQueued('u-dwipa');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->getJson('/api/v1/hris/queue', $h)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
