<?php

use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisMealService;

/*
 * Meal allowance (P1–P2): rates, preview, lock, export. Fixed September 2026
 * instants keep the recap deterministic. One HTTP identity per test.
 */

function hrisHadir(string $uid, int $day, int $h = 9, int $mi = 0): void
{
    app(HrisAttendanceService::class)->recordPunch(['id' => $uid, 'name' => $uid],
        ['arah' => 'MASUK'], hrisMs(2026, 9, $day, $h, $mi));
}

it('manages rates as HR', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $id = test()->postJson('/api/v1/hris/meal/tarifs',
        ['divisi' => '*', 'grade' => '', 'nominal' => 25000, 'berlaku_dari' => '2026-01-01'], $h)
        ->assertCreated()->json('data.id');

    $list = test()->getJson('/api/v1/hris/meal/tarifs', $h)->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['nominal'])->toBe(25000);

    test()->deleteJson("/api/v1/hris/meal/tarifs/{$id}", [], $h)->assertOk();
    expect(test()->getJson('/api/v1/hris/meal/tarifs', $h)->assertOk()->json('data'))->toBe([]);
});

it('keeps crew out of rates and locks', function () {
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->getJson('/api/v1/hris/meal/tarifs', $h)->assertStatus(403);
    test()->postJson('/api/v1/hris/meal/tarifs', ['nominal' => 1, 'berlaku_dari' => '2026-01-01'], $h)->assertStatus(403);
    test()->postJson('/api/v1/hris/meal/lock', ['bulan' => '2026-09'], $h)->assertStatus(403);
});

it('previews the recap with the default rate', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisMakeHr('u-admin');
    app(HrisMealService::class)->saveTarif(
        ['divisi' => '*', 'grade' => '', 'nominal' => 25000, 'berlaku_dari' => '2026-01-01'], 'test');
    hrisHadir('u-dwipa', 7);
    hrisHadir('u-dwipa', 8);
    hrisHadir('u-dwipa', 9);
    hrisHadir('u-andry', 7);

    $t = hrisToken('u-admin');
    $r = test()->getJson('/api/v1/hris/meal/preview?bulan=2026-09',
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    expect($r)->toMatchArray(['bulan' => '2026-09', 'terkunci' => false]);
    $byId = [];
    foreach ($r['baris'] as $b) {
        $byId[$b['user_id']] = $b;
    }
    expect($byId['u-dwipa'])->toMatchArray(['hari_hadir' => 3, 'nominal' => 25000, 'total' => 75000]);
    expect($byId['u-andry']['total'])->toBe(25000);
    expect($r['total'])->toBe(100000);
});

it('scopes the preview to the crew member', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisHadir('u-dwipa', 7);
    hrisHadir('u-andry', 7);

    $t = hrisToken('u-dwipa');
    $r = test()->getJson('/api/v1/hris/meal/preview?bulan=2026-09',
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    expect(array_column($r['baris'], 'user_id'))->toBe(['u-dwipa']);
});

it('refuses to lock with a non-empty queue', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisMakeHr('u-admin');
    app(HrisMealService::class)->saveTarif(
        ['divisi' => '*', 'grade' => '', 'nominal' => 25000, 'berlaku_dari' => '2026-01-01'], 'test');
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);
    app(HrisAttendanceService::class)->recordPunch(['id' => 'u-dwipa', 'name' => 'Dwipa'],
        ['arah' => 'MASUK', 'lat' => -6.0, 'lng' => 107.5, 'alasan' => 'dinas'], hrisMs(2026, 9, 7, 9, 0));

    $t = hrisToken('u-admin');
    test()->postJson('/api/v1/hris/meal/lock', ['bulan' => '2026-09'],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(422)->assertJsonPath('error.code', 'antrean_belum_kosong');
});

it('locks a snapshot and exports CSV', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisMakeHr('u-admin');
    app(HrisMealService::class)->saveTarif(
        ['divisi' => '*', 'grade' => '', 'nominal' => 25000, 'berlaku_dari' => '2026-01-01'], 'test');
    hrisHadir('u-dwipa', 7);
    hrisHadir('u-dwipa', 8);

    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->postJson('/api/v1/hris/meal/lock', ['bulan' => '2026-09'], $h)->assertOk()->json('data');

    expect($r)->toMatchArray(['bulan' => '2026-09', 'terkunci' => true, 'total' => 50000]);
    test()->postJson('/api/v1/hris/meal/lock', ['bulan' => '2026-09'], $h)
        ->assertStatus(409)->assertJsonPath('error.code', 'sudah_terkunci');

    // Later punches do not move a locked snapshot.
    hrisHadir('u-dwipa', 9);
    $r = test()->getJson('/api/v1/hris/meal?bulan=2026-09', $h)->assertOk()->json('data');
    expect($r['total'])->toBe(50000);

    test()->get('/api/v1/hris/meal/export?bulan=2026-09', $h)->assertOk()
        ->assertSee('user_id,divisi,grade,hari_hadir,nominal,total', false)
        ->assertSee('u-dwipa', false);
});
