<?php

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisCalendarService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/** Punch through the service (hrisPunch lives in B1PunchTest.php). */
function hrisRiwayatPunch(array $me, array $in, ?int $nowMs = null): array
{
    return app(HrisAttendanceService::class)->recordPunch($me, $in, $nowMs);
}

/*
 * Monthly attendance log (the Riwayat screen): one row per calendar day
 * plus the Talenta-style summary, assembled server-side so the PWA needs
 * no jadwal module grant. Only VALID punches count; MENUNGGU is reported
 * but never counted; DITOLAK is ignored entirely.
 */

function hrisRiwayatBulan(): void
{
    HrisKetukan::query()->where('user_id', 'u-dwipa')
        ->whereBetween('tanggal_kerja', ['2026-09-01', '2026-09-30'])->delete();
    hrisClearMonth('u-dwipa', '2026-09');
    hrisPlantShift('u-dwipa', '2026-09-03', 'PAGI', '08:00', '17:00');
    hrisPlantShift('u-dwipa', '2026-09-04', 'PAGI', '08:00', '17:00');
    hrisPlantShift('u-dwipa', '2026-09-05', 'OFF', '', '');
    hrisPlantShift('u-dwipa', '2026-09-06', 'CUTI', '', '');
    hrisPlantShift('u-dwipa', '2026-09-08', 'PAGI', '08:00', '17:00');
    hrisPlantShift('u-dwipa', '2026-09-09', 'PAGI', '08:00', '17:00');
    app(HrisCalendarService::class)->save(
        ['tanggal' => '2026-09-07', 'nama' => 'Libur uji', 'jenis' => 'NASIONAL'], 'test');
}

it('serves one row per day with the summary over HTTP', function () {
    hrisSettings([]);
    hrisEnrolOnce('u-dwipa');
    hrisRiwayatBulan();
    $t = hrisToken('u-dwipa');

    // Late MASUK + early PULANG, both VALID.
    hrisRiwayatPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'MASUK'), hrisMs(2026, 9, 3, 8, 20));
    hrisRiwayatPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 9, 3, 12, 0));
    // PULANG without MASUK: queued TANPA_MASUK, reported but not counted.
    hrisRiwayatPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'PULANG', ['alasan' => 'lupa absen masuk']),
        hrisMs(2026, 9, 8, 17, 30));
    // DITOLAK vanishes: the day reads as if the punch never happened.
    $antre = hrisRiwayatPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'PULANG', ['alasan' => 'salah tekan']),
        hrisMs(2026, 9, 9, 17, 0));
    // Decided through the service: one HTTP identity per test, and the
    // permission path itself is covered by B1DecisionTest.
    app(HrisAttendanceService::class)->decide($antre['punch']['id'], 'DITOLAK', '', 'Admin', 1);

    $r = test()->getJson('/api/v1/hris/riwayat?bulan=2026-09', ['Authorization' => 'Bearer '.$t])
        ->assertOk()->json('data');

    expect($r)->toHaveKeys(['bulan', 'dari', 'sampai', 'hari', 'ringkasan', 'rosterTakTerbaca', 'lokasi'])
        ->and($r['bulan'])->toBe('2026-09')
        ->and($r['dari'])->toBe('2026-09-01')
        ->and($r['sampai'])->toBe('2026-09-30')
        ->and(count($r['hari']))->toBe(30)
        ->and($r['rosterTakTerbaca'])->toBeFalse()
        ->and($r['ringkasan'])->toMatchArray(['terlambat' => 1, 'pulang_awal' => 1, 'absen' => 2,
            'tidak_clock_in' => 1, 'hadir' => 1, 'menunggu' => 1, 'terjadwal' => 4]);

    $hari = [];
    foreach ($r['hari'] as $h) {
        $hari[$h['tanggal']] = $h;
    }
    expect($hari['2026-09-03']['jenis'])->toBe('kerja')
        ->and($hari['2026-09-03']['masuk'])->toMatchArray(['status' => 'VALID', 'telat_menit' => 15])
        ->and($hari['2026-09-03']['pulang'])->toMatchArray(['status' => 'VALID', 'pulang_awal' => true])
        ->and($hari['2026-09-04']['jenis'])->toBe('absen')
        ->and($hari['2026-09-05']['jenis'])->toBe('off')
        ->and($hari['2026-09-06']['jenis'])->toBe('cuti')
        ->and($hari['2026-09-07']['jenis'])->toBe('libur-umum')
        ->and($hari['2026-09-07']['kalender']['nama'])->toBe('Libur uji')
        ->and($hari['2026-09-08']['jenis'])->toBe('kerja')
        ->and($hari['2026-09-08']['tanpaMasuk'])->toBeTrue()
        ->and($hari['2026-09-08']['pulang']['status'])->toBe('MENUNGGU')
        ->and($hari['2026-09-09']['jenis'])->toBe('absen')
        ->and($hari['2026-09-09']['masuk'])->toBeNull()
        ->and($hari['2026-09-09']['pulang'])->toBeNull();
});

it('validates the month, and HR without a filter reads their own log', function () {
    hrisSettings([]);
    hrisMakeHr('u-admin');
    $ht = hrisToken('u-admin');

    // One HTTP identity per test: every validation call below rides the
    // HR token, so the guard cache never changes mid-test.
    test()->getJson('/api/v1/hris/riwayat?bulan=2026-13', ['Authorization' => 'Bearer '.$ht])
        ->assertStatus(422);
    test()->getJson('/api/v1/hris/riwayat', ['Authorization' => 'Bearer '.$ht])
        ->assertStatus(422);
    // No filter always means the caller's own log, whatever the lingkup.
    $r = test()->getJson('/api/v1/hris/riwayat?bulan=2026-09', ['Authorization' => 'Bearer '.$ht])
        ->assertOk()->json('data');
    expect($r['bulan'])->toBe('2026-09')
        ->and(count($r['hari']))->toBe(30);
});

it('refuses crew readers of another log', function () {
    hrisSettings([]);
    $t = hrisToken('u-dwipa');

    test()->getJson('/api/v1/hris/riwayat?bulan=2026-09&user=u-admin', ['Authorization' => 'Bearer '.$t])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('serves crew their own log without a filter', function () {
    hrisSettings([]);
    hrisEnrolOnce('u-dwipa');
    hrisRiwayatBulan();
    hrisRiwayatPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'MASUK'), hrisMs(2026, 9, 3, 8, 0));
    $t = hrisToken('u-dwipa');

    $r = test()->getJson('/api/v1/hris/riwayat?bulan=2026-09', ['Authorization' => 'Bearer '.$t])
        ->assertOk()->json('data');
    expect($r['hari'][2]['masuk']['user_id'])->toBe('u-dwipa');
});

it('lets HR read the log of others', function () {
    hrisSettings([]);
    hrisEnrolOnce('u-dwipa');
    hrisRiwayatBulan();
    hrisRiwayatPunch(hrisMe('u-dwipa'), hrisWajahPunch('u-dwipa', 'MASUK'), hrisMs(2026, 9, 3, 8, 0));
    hrisMakeHr('u-admin');
    $ht = hrisToken('u-admin');

    $r = test()->getJson('/api/v1/hris/riwayat?bulan=2026-09&user=u-dwipa', ['Authorization' => 'Bearer '.$ht])
        ->assertOk()->json('data');
    expect($r['hari'][2]['masuk']['user_id'])->toBe('u-dwipa');
});

it('marks future days without counting them', function () {
    hrisSettings([]);
    hrisRiwayatBulan();
    $t = hrisToken('u-dwipa');

    $r = test()->getJson('/api/v1/hris/riwayat?bulan=2999-01', ['Authorization' => 'Bearer '.$t])
        ->assertOk()->json('data');

    expect(count($r['hari']))->toBe(31);
    expect($r['hari'][0]['jenis'])->toBe('masa-depan');
    expect($r['ringkasan'])->toMatchArray(['terlambat' => 0, 'pulang_awal' => 0, 'absen' => 0, 'tidak_clock_in' => 0, 'hadir' => 0, 'menunggu' => 0]);
});
