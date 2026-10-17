<?php

use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisBalanceService;
use App\Modules\Hris\Services\HrisRequestService;

require_once __DIR__.'/helpers.php';

/*
 * List projections carry the display name (and division for punches), so
 * the HR console never renders a bare user id. One HTTP identity per test.
 */

it('lists faces with names', function () {
    $me = hrisMe('u-dwipa');
    app(HrisAttendanceService::class)->enrol($me, [
        'descriptor' => hrisDescriptor(), 'model' => 'test-m', 'foto' => hrisFoto(), 'consent' => true,
    ]);
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');

    $rows = test()->getJson('/api/v1/hris/faces', ['Authorization' => 'Bearer '.$t])
        ->assertOk()->json('data');

    $row = collect($rows)->firstWhere('user_id', 'u-dwipa');
    expect($row)->not->toBeNull();
    expect($row['nama'])->toBe($me['name']);
});

it('shapes punches with name and division', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    app(HrisAttendanceService::class)->recordPunch(['id' => 'u-dwipa', 'name' => 'u-dwipa'],
        ['arah' => 'MASUK', 'alasan' => 'uji'], hrisMs(2026, 10, 23, 9, 0));
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $tgl = hrisDate(2026, 10, 23);

    $rows = test()->getJson("/api/v1/hris/punches?from={$tgl}&to={$tgl}",
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data.ketukan');

    expect($rows)->not->toBe([]);
    expect($rows[0]['nama'])->toBe(hrisMe('u-dwipa')['name']);
    expect($rows[0])->toHaveKey('divisi');
});

it('shapes requests and balances with names', function () {
    hrisSettings();
    $me = hrisMe('u-dwipa');
    $row = app(HrisRequestService::class)->create($me, [
        'jenis' => 'IZIN', 'dari' => hrisDate(2026, 11, 3), 'sampai' => hrisDate(2026, 11, 3),
        'alasan' => 'uji', 'lampiran' => hrisFoto(), 'lampiran_nama' => 'bukti.jpg',
    ]);
    expect($row)->not->toBeNull();
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');

    $list = test()->getJson('/api/v1/hris/requests?status=MENUNGGU',
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    $satu = collect($list)->firstWhere('id', $row->id);
    expect($satu['nama'])->toBe($me['name']);

    $bal = test()->getJson('/api/v1/hris/balances?user=u-dwipa',
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($bal[0]['nama'])->toBe($me['name']);
});

it('scopes the mutation history by Lingkup', function () {
    hrisMakeHr('u-admin');
    $tHr = hrisToken('u-admin');
    app(HrisBalanceService::class)
        ->adjust('u-admin', 'u-dwipa', 'CUTI', 2, 'uji riwayat');

    $m = test()->getJson('/api/v1/hris/balances/mutations?user=u-dwipa',
        ['Authorization' => 'Bearer '.$tHr])->assertOk()->json('data');
    expect($m['user_id'])->toBe('u-dwipa');
    expect(collect($m['mutasi'])->firstWhere('catatan', 'uji riwayat'))->not->toBeNull();
});

it('refuses crew readers of foreign mutation history', function () {
    $tCrew = hrisToken('u-dwipa');
    test()->getJson('/api/v1/hris/balances/mutations?user=u-admin',
        ['Authorization' => 'Bearer '.$tCrew])->assertStatus(403);
});

it('shows crew their own mutation history', function () {
    $tCrew = hrisToken('u-dwipa');
    test()->getJson('/api/v1/hris/balances/mutations',
        ['Authorization' => 'Bearer '.$tCrew])->assertOk();
});
