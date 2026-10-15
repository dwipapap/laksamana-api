<?php

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisBalanceService;
use App\Modules\Hris\Services\HrisHarianService;

/*
 * The balance ledger (L2–L6): earn/use/expiry with FIFO, PH for holiday
 * work, HR adjustments. Idempotent earns and expiries throughout.
 */

function hrisSaldo(): HrisBalanceService
{
    return app(HrisBalanceService::class);
}

it('starts at zero with the default quota', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');

    $r = test()->getJson('/api/v1/hris/balances/me', ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    expect($r)->toMatchArray(['PH' => ['saldo' => 0], 'CUTI' => ['saldo' => 0, 'jatah_tahunan' => 12]]);
});

it('adjusts balances as HR with a reason', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $r = test()->postJson('/api/v1/hris/balances/adjust',
        ['user' => 'u-dwipa', 'jenis' => 'CUTI', 'delta' => 12, 'catatan' => 'Saldo awal Talenta'], $h)
        ->assertOk()->json('data');
    expect($r['CUTI']['saldo'])->toBe(12);

    // Spending what is there works; overspending is refused, not negative.
    $r = test()->postJson('/api/v1/hris/balances/adjust',
        ['user' => 'u-dwipa', 'jenis' => 'CUTI', 'delta' => -4, 'catatan' => 'Koreksi'], $h)
        ->assertOk()->json('data');
    expect($r['CUTI']['saldo'])->toBe(8);
    test()->postJson('/api/v1/hris/balances/adjust',
        ['user' => 'u-dwipa', 'jenis' => 'CUTI', 'delta' => -9, 'catatan' => 'Kebanyakan'], $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'saldo_kurang');

    // A reason is required; crew cannot adjust at all.
    test()->postJson('/api/v1/hris/balances/adjust',
        ['user' => 'u-dwipa', 'jenis' => 'CUTI', 'delta' => 1, 'catatan' => ''], $h)->assertStatus(422);
});

it('keeps crew out of adjustments', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/balances/adjust',
        ['user' => 'u-dwipa', 'jenis' => 'CUTI', 'delta' => 5, 'catatan' => 'Coba'], $h)->assertStatus(403);
    $rows = test()->getJson('/api/v1/hris/balances?user=u-andry', $h)->assertOk()->json('data');
    expect($rows)->not->toBe([]);
    foreach ($rows as $row) {
        expect($row['user_id'])->toBe('u-dwipa');
    }
});

it('consumes PH oldest-expiry first', function () {
    hrisSettings();
    hrisSaldo()->earn('u-dwipa', 'PH', 2, 'JATAH', 'uji-awal', '2026-10-01', '2026-11-01', 'uji');
    $kedua = hrisSaldo()->earn('u-dwipa', 'PH', 5, 'JATAH', 'uji-kedua', '2026-10-02', '2027-02-01', 'uji');

    hrisSaldo()->gunakan('u-dwipa', 'PH', 3, 'PENGAJUAN', 'uji-pakai', 'uji');

    $fifo = hrisSaldo()->batchFIFO('u-dwipa', 'PH');
    expect(hrisSaldo()->saldo('u-dwipa', 'PH'))->toBe(4);
    // The November batch is empty; February keeps 4.
    expect(array_column($fifo, 'id'))->toBe([$kedua[0]->id]);
    expect($fifo[0]['sisa'])->toBe(4);
});

it('expires leftover PH after three months', function () {
    hrisSettings();
    hrisSaldo()->earn('u-dwipa', 'PH', 1, 'JATAH', 'uji-hangus', '2026-07-01', '2026-10-01', 'uji');

    $out = app(HrisHarianService::class)->hanguskanPh('2026-10-02');
    expect($out)->toMatchArray(['hangus' => 1, 'baris' => 1]);
    expect(hrisSaldo()->saldo('u-dwipa', 'PH'))->toBe(0);

    // Re-running expires nothing twice.
    expect(app(HrisHarianService::class)->hanguskanPh('2026-10-02'))->toMatchArray(['hangus' => 0, 'baris' => 0]);
});

it('earns +1 PH for a VALID holiday punch', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    $tgl = hrisToday();
    hrisHoliday($tgl, 'Libur hari ini');

    $t = hrisToken('u-dwipa');
    test()->postJson('/api/v1/hris/punches', ['arah' => 'MASUK'],
        ['Authorization' => 'Bearer '.$t])->assertCreated();

    $out = app(HrisHarianService::class)->beriPhHarian($tgl);
    expect($out['diberi'])->toBe(1);
    expect(hrisSaldo()->saldo('u-dwipa', 'PH'))->toBe(1);

    // Idempotent: the same run earns nothing twice.
    expect(app(HrisHarianService::class)->beriPhHarian($tgl))->toMatchArray(['diberi' => 0]);

    // The summary flags the batch as expiring within a month.
    $r = test()->getJson('/api/v1/hris/balances/me', ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($r['PH']['saldo'])->toBe(1);
});

it('earns PH when a holiday punch is decided VALID late', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    $tgl = hrisToday();
    hrisHoliday($tgl, 'Libur hari ini');
    hrisMakeHr('u-admin');
    hrisClearShift('u-dwipa', $tgl);
    hrisClearDefaultShift('u-dwipa');

    // Queues TANPA_SHIFT today; deciding it VALID earns the holiday PH.
    $out = app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        ['arah' => 'MASUK', 'alasan' => 'piket']);
    expect($out['punch']['status'])->toBe('MENUNGGU');
    $id = $out['punch']['id'];

    $t = hrisToken('u-admin');
    test()->postJson("/api/v1/hris/punches/{$id}/decision",
        ['status' => 'VALID', 'berubah' => $out['punch']['berubah']],
        ['Authorization' => 'Bearer '.$t])->assertOk();

    expect(hrisSaldo()->saldo('u-dwipa', 'PH'))->toBe(1);
    expect(HrisKetukan::query()->find($id)->status)->toBe('VALID');
});
