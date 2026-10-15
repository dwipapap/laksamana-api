<?php

use App\Modules\Hris\Models\HrisPushToken;
use App\Modules\Hris\Models\HrisReminderLog;
use App\Modules\Hris\Services\ExpoPushService;
use App\Modules\Hris\Services\HrisBalanceService;
use App\Modules\Hris\Services\HrisReminderService;
use Illuminate\Support\Facades\Http;

/*
 * Push reminders (B4): devices, geofence nudges, lateness sweeps, PH
 * expiry, waiting approvals, decision notices. Expo HTTP is faked; every
 * send stays idempotent per (jenis, ref, user).
 */

function hrisExpoOk(): void
{
    Http::fake([
        ExpoPushService::TIKET_URL => Http::response(['data' => [['status' => 'ok', 'id' => 't1']]], 200),
        ExpoPushService::TANDA_URL => Http::response(['data' => ['t1' => ['status' => 'ok']]], 200),
    ]);
}

function hrisTokenFor(string $uid): void
{
    HrisPushToken::query()->create(['user_id' => $uid,
        'token' => 'ExponentPushToken['.$uid.']', 'platform' => 'android']);
}

it('registers and removes devices', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/devices',
        ['token' => 'salah', 'platform' => 'android'], $h)->assertStatus(422);
    test()->postJson('/api/v1/hris/devices',
        ['token' => 'ExponentPushToken[abc123]', 'platform' => 'ios'], $h)
        ->assertCreated()->assertJsonPath('data.terdaftar', true);
    expect(HrisPushToken::query()->where('user_id', 'u-dwipa')->count())->toBe(1);

    test()->deleteJson('/api/v1/hris/devices', ['token' => 'ExponentPushToken[abc123]'], $h)
        ->assertOk()->assertJsonPath('data.dihapus', 1);
});

it('nudges ENTER without MASUK and EXIT without PULANG, once a day', function () {
    hrisExpoOk();
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisTokenFor('u-dwipa');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    $r = test()->postJson('/api/v1/hris/geofence-events', ['tipe' => 'ENTER'], $h)->assertOk()->json('data');
    expect($r)->toMatchArray(['tipe' => 'ENTER', 'terkirim' => true]);
    expect(HrisReminderLog::query()->where('jenis', 'masuk_tanpa_absen')->count())->toBe(1);

    // Idempotent: the same crossing nudges once.
    $r = test()->postJson('/api/v1/hris/geofence-events', ['tipe' => 'ENTER'], $h)->assertOk()->json('data');
    expect($r['terkirim'])->toBeFalse();

    test()->postJson('/api/v1/hris/geofence-events', ['tipe' => 'KELUAR'], $h)->assertStatus(422);
});

it('nudges EXIT only with an open MASUK', function () {
    hrisExpoOk();
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisTokenFor('u-dwipa');

    // No MASUK at all: EXIT stays silent.
    $r = app(HrisReminderService::class)->geofence('u-dwipa', '', 'EXIT', hrisMs(2026, 10, 20, 18, 0));
    expect($r['terkirim'])->toBeFalse();

    app(App\Modules\Hris\Services\HrisAttendanceService::class)->recordPunch(['id' => 'u-dwipa', 'name' => 'Dwipa'],
        ['arah' => 'MASUK'], hrisMs(2026, 10, 20, 9, 0));
    $r = app(HrisReminderService::class)->geofence('u-dwipa', '', 'EXIT', hrisMs(2026, 10, 20, 18, 0));
    expect($r['terkirim'])->toBeTrue();

    app(App\Modules\Hris\Services\HrisAttendanceService::class)->recordPunch(['id' => 'u-dwipa', 'name' => 'Dwipa'],
        ['arah' => 'PULANG'], hrisMs(2026, 10, 20, 18, 5));
    // A new day without MASUK: EXIT stays silent again (PULANG exists, MASUK does not).
    $r = app(HrisReminderService::class)->geofence('u-dwipa', '', 'EXIT', hrisMs(2026, 10, 21, 18, 0));
    expect($r['terkirim'])->toBeFalse();
});

it('deletes dead tokens and never logs unsent reminders', function () {
    Http::fake([
        ExpoPushService::TIKET_URL => Http::response(
            ['data' => [['status' => 'error', 'message' => 'DeviceNotRegistered']]], 200),
    ]);
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisTokenFor('u-dwipa');

    $r = app(HrisReminderService::class)->geofence('u-dwipa', '', 'ENTER', hrisMs(2026, 10, 20, 9, 0));
    expect($r['terkirim'])->toBeFalse();
    expect(HrisPushToken::query()->where('user_id', 'u-dwipa')->exists())->toBeFalse();
    expect(HrisReminderLog::query()->count())->toBe(0);
});

it('sweeps late arrivals after the shift starts', function () {
    hrisExpoOk();
    hrisSettings(['tanpaShiftBoleh' => false, 'reminderTelatMenit' => 30]);
    hrisTokenFor('u-dwipa');
    hrisTokenFor('u-andry');
    $tgl = hrisDate(2026, 10, 20);
    hrisPlantShift('u-dwipa', $tgl, 'PAGI', '08:00', '17:00');
    hrisPlantShift('u-andry', $tgl, 'PAGI', '08:00', '17:00');
    // u-andry already punched in; u-dwipa did not.
    app(App\Modules\Hris\Services\HrisAttendanceService::class)->recordPunch(['id' => 'u-andry', 'name' => 'Andry'],
        ['arah' => 'MASUK'], hrisMs(2026, 10, 20, 8, 5));

    // Too early: nobody is late yet.
    expect(app(HrisReminderService::class)->telatMasuk(hrisMs(2026, 10, 20, 8, 10)))
        ->toMatchArray(['diingatkan' => 0]);

    $r = app(HrisReminderService::class)->telatMasuk(hrisMs(2026, 10, 20, 9, 0));
    expect($r)->toMatchArray(['diingatkan' => 1]);
    expect(HrisReminderLog::query()->where('jenis', 'belum_masuk')->where('user_id', 'u-dwipa')->exists())->toBeTrue();

    hrisClearShift('u-dwipa', $tgl);
    hrisClearShift('u-andry', $tgl);
});

it('warns PH expiring within 14 days only', function () {
    hrisExpoOk();
    hrisSettings();
    hrisTokenFor('u-dwipa');
    $svc = app(HrisBalanceService::class);
    $svc->earn('u-dwipa', 'PH', 1, 'JATAH', 'uji-dekat', '2026-10-01', '2026-10-25', 'uji');
    $svc->earn('u-dwipa', 'PH', 1, 'JATAH', 'uji-jauh', '2026-10-01', '2026-12-01', 'uji');

    $r = app(HrisReminderService::class)->phSegeraHangus('2026-10-20');
    expect($r)->toMatchArray(['diingatkan' => 1]);
    expect(HrisReminderLog::query()->where('jenis', 'ph_hangus')->count())->toBe(1);
});

it('notifies heads of waiting requests', function () {
    hrisExpoOk();
    hrisSettings();
    hrisTokenFor('u-arif');
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    app(HrisBalanceService::class)->adjust('test', $crew, 'CUTI', 12, 'uji');
    $req = app(App\Modules\Hris\Services\HrisRequestService::class)->create(hrisMe($crew),
        ['jenis' => 'CUTI', 'dari' => '2026-11-02', 'sampai' => '2026-11-02', 'alasan' => 'uji']);

    $r = app(HrisReminderService::class)->pengajuanMenunggu();
    expect($r['diingatkan'])->toBeGreaterThanOrEqual(1);
    expect(HrisReminderLog::query()->where('ref', $req->id.'|kepala')->where('user_id', 'u-arif')->exists())->toBeTrue();

    // Idempotent across runs.
    expect(app(HrisReminderService::class)->pengajuanMenunggu()['diingatkan'])->toBe(0);
});

it('notifies decisions without leaking data', function () {
    hrisExpoOk();
    hrisSettings();
    hrisTokenFor('u-dwipa');

    expect(app(HrisReminderService::class)->putusanKetukan('u-dwipa', 'ket-1', 'VALID'))->toBeTrue();
    $log = HrisReminderLog::query()->where('jenis', 'putusan_antrean')->first();
    expect($log->ref)->toBe('ket-1|VALID');

    Http::assertSent(function ($req) {
        $body = $req->data();
        $text = json_encode($body);

        return str_contains($req->url(), 'exp.host') && ! str_contains($text, 'u-dwipa');
    });
});

it('keeps the Expo token out of client reads', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->putJson('/api/v1/hris/settings',
        ['data' => ['expoAccessToken' => 'rahasiasekali']], $h)->assertOk();
    $r = test()->getJson('/api/v1/hris/settings', $h)->assertOk()->json('data');
    expect($r)->not->toHaveKey('expoAccessToken');
    expect(app(App\Modules\Hris\Services\HrisSettingsService::class)->get('expoAccessToken'))->toBe('rahasiasekali');
});

it('survives an unreadable Akademi', function () {
    hrisSettings();
    $r = app(HrisReminderService::class)->tenggatBelajar(hrisMs(2026, 10, 20, 7, 0));
    expect($r)->toHaveKey('diingatkan');
});
