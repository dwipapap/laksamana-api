<?php

use App\Modules\Hris\Services\HrisImporKaryawanService;
use App\Modules\Hris\Services\HrisKaryawanService;
use App\Modules\Hris\Services\HrisShiftService;
use App\Modules\Hris\Services\HrisTimRosterService;
use App\Modules\Jadwal\Services\JadwalService;

require_once __DIR__.'/helpers.php';

/*
 * Crew team roster (issue #346): read-only, all divisions, explicit cells
 * only. One HTTP identity per test; other actors via the services.
 */

function timSiap(): void
{
    app(HrisImporKaryawanService::class)->impor('test');
}

function timCrew(string $uid = 'u-yuzaalfarel'): array
{
    return ['Authorization' => 'Bearer '.hrisToken($uid)];
}

function timHr(): array
{
    hrisMakeHr('u-admin');

    return ['Authorization' => 'Bearer '.hrisToken('u-admin')];
}

function timTanggal(int $plusHari = 2): string
{
    return date('Y-m-d', strtotime(hrisToday().' +'.$plusHari.' days'));
}

it('shows the crew their own division by default with counts', function () {
    timSiap();
    $d = timTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'PAGI', '08:00', '17:00');

    $r = test()->getJson("/api/v1/hris/tim/roster?dari={$d}&sampai={$d}", timCrew())
        ->assertOk()->json('data');

    $sendiri = app(HrisTimRosterService::class)->divisiSendiri('u-yuzaalfarel');
    expect($sendiri)->not()->toBe('');
    expect($r['divisi'])->toBe($sendiri)
        ->and($r['dari'])->toBe($d)->and($r['sampai'])->toBe($d)
        ->and($r['hari'][0]['jumlah'])->toBe(['dijadwalkan' => 1, 'tidak_masuk' => 0, 'belum_diatur' => count($r['hari'][0]['kru']) - 1]);

    $saya = collect($r['hari'][0]['kru'])->firstWhere('user_id', 'u-yuzaalfarel');
    expect($saya['milik_sendiri'])->toBeTrue()
        ->and($saya['status'])->toBe('Dijadwalkan')
        ->and($saya['shift']['kode'])->toBe('PAGI')
        ->and($saya['shift']['mulai'])->toBe('08:00');
    expect(array_keys($saya))->not()->toContain('email', 'no_hp', 'nik', 'alasan');
});

it('reads every division and validates filters', function () {
    timSiap();
    $d = timTanggal();

    test()->getJson("/api/v1/hris/tim/roster?dari={$d}&sampai={$d}&divisi=semua", timCrew())->assertOk();
    test()->getJson("/api/v1/hris/tim/roster?dari={$d}&sampai={$d}&divisi=tidak-ada", timCrew())->assertStatus(422);
    test()->getJson("/api/v1/hris/tim/roster?dari={$d}&sampai={$d}", timCrew())->assertOk();
    $jauh = date('Y-m-d', strtotime($d.' +40 days'));
    test()->getJson("/api/v1/hris/tim/roster?dari={$d}&sampai={$jauh}", timCrew())->assertStatus(422);
});

it('keeps empty cells Belum diatur even with a fallback shift', function () {
    timSiap();
    $d = timTanggal();
    app(HrisKaryawanService::class)->update('u-yuzaalfarel', ['shift_bawaan' => 'PAGI'], 'test');

    $r = test()->getJson("/api/v1/hris/tim/roster?dari={$d}&sampai={$d}", timCrew())->assertOk()->json('data');
    $saya = collect($r['hari'][0]['kru'])->firstWhere('user_id', 'u-yuzaalfarel');
    expect($saya['status'])->toBe('Belum diatur')->and($saya['shift'])->toBeNull();
});

it('marks off cells Tidak masuk and flags unknown shifts as data problems', function () {
    timSiap();
    $d = timTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'OFF', '', '');
    app(JadwalService::class)->saveCells(
        [['u' => 'u-arif', 'd' => $d, 't' => 'XX_TAK_ADA', 'm' => '', 's' => '']],
        [], 'test', ['id' => 'u-admin', 'name' => 'Admin', 'adminModules' => ['*']]);

    $r = test()->getJson("/api/v1/hris/tim/roster?dari={$d}&sampai={$d}", timCrew())->assertOk()->json('data');
    $saya = collect($r['hari'][0]['kru'])->firstWhere('user_id', 'u-yuzaalfarel');
    expect($saya['status'])->toBe('Tidak masuk')->and($saya['shift']['libur'])->toBeTrue();
    expect($r['masalah'])->toHaveCount(1);
    expect($r['masalah'][0])->toMatchArray(['user_id' => 'u-arif', 'tanggal' => $d, 'kode' => 'XX_TAK_ADA']);
    $j = $r['hari'][0]['jumlah'];
    expect($j['dijadwalkan'] + $j['tidak_masuk'] + $j['belum_diatur'])->toBe(count($r['hari'][0]['kru']));
});

it('counts a split shift once and marks overnight starts', function () {
    timSiap();
    app(HrisShiftService::class)->create(
        ['nama' => 'Uji Split', 'warna' => '#112233',
            'blok' => [['mulai' => '08:00', 'selesai' => '12:00'], ['mulai' => '13:00', 'selesai' => '21:00']]], 'test');
    $d = timTanggal();
    hrisPlantShift('u-yuzaalfarel', $d, 'UJISPLIT', '', '');

    $r = test()->getJson("/api/v1/hris/tim/roster?dari={$d}&sampai={$d}", timCrew())->assertOk()->json('data');
    $saya = collect($r['hari'][0]['kru'])->firstWhere('user_id', 'u-yuzaalfarel');
    expect($saya['status'])->toBe('Dijadwalkan')->and($saya['shift']['blok'])->toHaveCount(2);
    expect($r['hari'][0]['jumlah']['dijadwalkan'])->toBe(1);
});

it('lists divisions for the filter without exposing heads or members', function () {
    timSiap();

    $r = test()->getJson('/api/v1/hris/tim/divisi', timCrew())->assertOk()->json('data');
    expect($r)->not()->toBe([]);
    expect($r[0])->toHaveKeys(['id', 'kode', 'nama', 'jenis']);
    expect(array_keys($r[0]))->not()->toContain('kepala', 'user_id', 'email');
});

it('refuses the HR audit trail to crew', function () {
    timSiap();
    test()->getJson('/api/v1/hris/kelola/roster/riwayat', timCrew('u-yuzaalfarel'))->assertForbidden();
});

it('refuses the HR audit trail to heads', function () {
    timSiap();
    test()->getJson('/api/v1/hris/kelola/roster/riwayat', timCrew('u-arif'))->assertForbidden();
});

it('opens the HR audit trail to HR admins', function () {
    timSiap();
    test()->getJson('/api/v1/hris/kelola/roster/riwayat', timHr())->assertOk();
});
