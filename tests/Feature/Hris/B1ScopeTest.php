<?php

use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Jadwal\Services\JadwalService;

/*
 * Lingkup (sendiri/divisi/semua): crew see only their own rows, a Kepala
 * Divisi sees their divisions, HR sees everyone. One HTTP identity per test.
 */

function hrisPlantPunch(string $uid, string $arah = 'MASUK'): void
{
    hrisSettings(['tanpaShiftBoleh' => true]);
    app(HrisAttendanceService::class)->recordPunch(['id' => $uid, 'name' => $uid],
        ['arah' => $arah], hrisMs(2026, 10, 23, 9, 0));
}

it('forces crew history to their own rows', function () {
    hrisPlantPunch('u-dwipa');
    hrisPlantPunch('u-andry');
    $t = hrisToken('u-dwipa');
    $tgl = hrisDate(2026, 10, 23);

    $r = test()->getJson("/api/v1/hris/punches?from={$tgl}&to={$tgl}&user=u-andry",
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    expect($r['ketukan'])->not->toBe([]);
    foreach ($r['ketukan'] as $row) {
        expect($row['user_id'])->toBe('u-dwipa');
    }
});

it('shows HR every row', function () {
    hrisMakeHr('u-admin');
    hrisPlantPunch('u-dwipa');
    hrisPlantPunch('u-andry');
    $t = hrisToken('u-admin');
    $tgl = hrisDate(2026, 10, 23);

    $r = test()->getJson("/api/v1/hris/punches?from={$tgl}&to={$tgl}",
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    $ids = array_unique(array_column($r['ketukan'], 'user_id'));
    sort($ids);
    expect($ids)->toContain('u-dwipa', 'u-andry');
});

it('scopes a Kepala Divisi to their divisions', function () {
    // u-arif heads bar in the restored dump (see Jadwal DivisiTest).
    hrisMakeHr('u-admin'); // not used for auth here; keeps grants consistent
    hrisPlantPunch('u-dwipa');
    hrisPlantPunch('u-andry');
    $t = hrisToken('u-arif');
    $tgl = hrisDate(2026, 10, 23);

    $r = test()->getJson("/api/v1/hris/punches?from={$tgl}&to={$tgl}",
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    $me = test()->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($me['headDivisi'])->toContain('bar');

    $allowed = ['u-arif'];
    foreach (app(JadwalService::class)->rosterWithDivisi() as $x) {
        $x = (array) $x;
        if (in_array((string) ($x['divisi'] ?? ''), $me['headDivisi'], true) && isset($x['id'])) {
            $allowed[] = (string) $x['id'];
        }
    }
    $allowed = array_values(array_unique($allowed));
    foreach ($r['ketukan'] as $row) {
        expect(in_array($row['user_id'], $allowed, true))->toBeTrue("{$row['user_id']} outside bar scope");
    }

    // A user outside the headed divisions is refused, not silently emptied.
    $foreign = 'u-admin';
    foreach (app(JadwalService::class)->rosterWithDivisi() as $x) {
        $x = (array) $x;
        if (isset($x['id']) && (string) $x['id'] !== 'u-arif'
            && ! in_array((string) ($x['divisi'] ?? ''), $me['headDivisi'], true)) {
            $foreign = (string) $x['id'];
            break;
        }
    }
    test()->getJson("/api/v1/hris/punches?from={$tgl}&to={$tgl}&user={$foreign}",
        ['Authorization' => 'Bearer '.$t])->assertStatus(403);
});

it('lets a Kepala Divisi see their division queue but not decide their own', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);
    $out = app(HrisAttendanceService::class)->recordPunch(['id' => 'u-arif', 'name' => 'Arif'],
        ['arah' => 'MASUK', 'lat' => -6.0, 'lng' => 107.5, 'alasan' => 'dinas luar'],
        hrisMs(2026, 10, 20, 9, 0));
    expect($out['punch']['status'])->toBe('MENUNGGU');
    $id = $out['punch']['id'];
    $berubah = $out['punch']['berubah'];

    $t = hrisToken('u-arif');
    $h = ['Authorization' => 'Bearer '.$t];

    $q = test()->getJson('/api/v1/hris/queue', $h)->assertOk()->json('data');
    expect($q)->toBeArray();

    // No self-approval, even for a head.
    test()->postJson("/api/v1/hris/punches/{$id}/decision",
        ['status' => 'VALID', 'berubah' => $berubah], $h)->assertStatus(403);
});
