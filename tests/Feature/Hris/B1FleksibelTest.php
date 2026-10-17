<?php

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Jadwal\Services\JadwalService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/*
 * Flexible attendance (A10–A26): GPS accuracy gates the area, MASUK lives
 * in [mulai - awalMenit, selesai], PULANG is measured from the actual MASUK
 * against its duration target, suspicion flags never queue, and the punch
 * screen learns its rules from me/today. One HTTP identity per test; the
 * rest goes through the service directly.
 */

it('gates the area on GPS accuracy', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);
    $me = hrisMe('u-dwipa');

    // Inside the radius with a good fix: VALID.
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'PAGI', '08:00', '17:00');
    $bersih = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'MASUK', ['lat' => -6.2, 'lng' => 106.8, 'akurasi' => 12]),
        hrisMs(2026, 10, 20, 9, 0));
    expect($bersih['punch'])->toMatchArray(['status' => 'VALID', 'dalam_area' => true]);

    // Same point without accuracy: outside (no jarak-akurasi slack).
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 21), 'PAGI', '08:00', '17:00');
    $kosong = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'MASUK', ['lat' => -6.2, 'lng' => 106.8, 'alasan' => 'gps mati']),
        hrisMs(2026, 10, 21, 9, 0));
    expect($kosong['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['LUAR_AREA']]);

    // Worse than akurasiMaks (100): outside too.
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 22), 'PAGI', '08:00', '17:00');
    $buram = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'MASUK', ['lat' => -6.2, 'lng' => 106.8, 'akurasi' => 250, 'alasan' => 'gps buram']),
        hrisMs(2026, 10, 22, 9, 0));
    expect($buram['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['LUAR_AREA']]);

    foreach ([20, 21, 22] as $d) {
        hrisClearShift('u-dwipa', hrisDate(2026, 10, $d));
    }
});

it('resolves each PULANG target from its MASUK snapshot', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    $me = hrisMe('u-dwipa');

    // JADWAL snapshot 08:00–17:00: 540 minutes, stored on the row.
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'PAGI', '08:00', '17:00');
    app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 20, 8, 0));
    $pulang = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 20, 12, 0));
    expect($pulang['punch'])->toMatchArray(['status' => 'VALID', 'pulang_awal' => true]);
    expect($pulang['punch'])->toMatchArray(['target_menit' => 540, 'durasi_menit' => 240, 'kurang_menit' => 300]);
    expect(HrisKetukan::query()->find($pulang['punch']['id'])->info)->toMatchArray(['target_menit' => 540]);

    // Per-person override without a Jadwal cell.
    hrisSettings(['tanpaShiftBoleh' => true, 'durasiKerja' => ['default' => 540, 'orang' => ['u-dwipa' => 480]]]);
    hrisClearShift('u-dwipa', hrisDate(2026, 10, 21));
    hrisClearDefaultShift('u-dwipa');
    app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 21, 8, 0));
    $pas = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 21, 16, 0));
    expect($pas['punch'])->toMatchArray(['status' => 'VALID', 'pulang_awal' => false]);
    expect($pas['punch']['target_menit'])->toBe(480);

    // BAWAAN snapshot 13:00–22:00: 540 minutes.
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisClearShift('u-dwipa', hrisDate(2026, 10, 22));
    hrisSetDefaultShift('u-dwipa', 'SIANG', '13:00', '22:00');
    app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 22, 13, 0));
    $bawaan = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 22, 18, 0));
    expect($bawaan['punch']['target_menit'])->toBe(540);

    // Custom default with no snapshot at all.
    hrisSettings(['tanpaShiftBoleh' => true, 'durasiKerja' => ['default' => 480, 'orang' => []]]);
    hrisClearShift('u-dwipa', hrisDate(2026, 10, 23));
    hrisClearDefaultShift('u-dwipa');
    app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 23, 8, 0));
    $baku = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 23, 12, 0));
    expect($baku['punch']['target_menit'])->toBe(480);

    foreach ([20, 21, 22, 23] as $d) {
        hrisClearShift('u-dwipa', hrisDate(2026, 10, $d));
    }
});

it('queues a PULANG past target + pulangLewatMenit as LUAR_SHIFT', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    $me = hrisMe('u-dwipa');

    // Exactly target + grace (540 + 180 = 720): still VALID.
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'PAGI', '08:00', '17:00');
    app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 20, 8, 0));
    $pas = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 20, 20, 0));
    expect($pas['punch'])->toMatchArray(['status' => 'VALID', 'pulang_awal' => false]);

    // One minute more: out of policy.
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 21), 'PAGI', '08:00', '17:00');
    app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 21, 8, 0));
    $telat = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG', ['alasan' => 'lembur']),
        hrisMs(2026, 10, 21, 20, 1));
    expect($telat['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['LUAR_SHIFT']]);

    hrisClearShift('u-dwipa', hrisDate(2026, 10, 20));
    hrisClearShift('u-dwipa', hrisDate(2026, 10, 21));
});

it('queues TANPA_MASUK for a PULANG with no MASUK', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');

    $out = app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-dwipa', 'PULANG', ['alasan' => 'lupa absen masuk']),
        hrisMs(2026, 10, 20, 17, 0));

    expect($out['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['TANPA_MASUK']]);
});

it('ignores a DITOLAK MASUK when looking for TANPA_MASUK', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisMakeHr('u-admin');
    hrisEnrolOnce('u-dwipa');
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);
    $me = hrisMe('u-dwipa');

    // Far away with a reason: queued, then HR rejects it outright.
    $masuk = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'MASUK', ['lat' => -6.0, 'lng' => 107.5, 'akurasi' => 12, 'alasan' => 'dinas']),
        hrisMs(2026, 10, 20, 9, 0));
    expect($masuk['punch']['status'])->toBe('MENUNGGU');
    $tolak = app(HrisAttendanceService::class)->decide(
        $masuk['punch']['id'], 'DITOLAK', 'palsu', 'Admin', $masuk['punch']['berubah']);
    expect($tolak)->toMatchArray(['ok' => true]);

    // The rejected MASUK counts as no punch: TANPA_MASUK.
    $pulang = app(HrisAttendanceService::class)->recordPunch($me,
        hrisWajahPunch('u-dwipa', 'PULANG', ['lat' => -6.2, 'lng' => 106.8, 'akurasi' => 12, 'alasan' => 'pulang']),
        hrisMs(2026, 10, 20, 17, 0));
    expect($pulang['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['TANPA_MASUK']]);
});

it('still queues WAJAH when the roster cannot be read', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    $this->mock(JadwalService::class, function ($m): void {
        $m->shouldReceive('shiftRange')->andThrow(new RuntimeException('roster down'));
        $m->shouldReceive('divisiUser')->andReturn('');
    });

    // A stranger's face must not pass VALID just because Jadwal is down.
    $out = app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-andry', 'MASUK', ['alasan' => 'uji']),
        hrisMs(2026, 10, 20, 9, 0));

    expect($out['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH']]);
});

it('flags suspicion without queuing, HR eyes only', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    hrisEnrolOnce('u-andry');
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);
    $x = ['lat' => -6.2005, 'lng' => 106.8005];

    // Same point twice (MASUK then PULANG without moving): KOORDINAT_SAMA.
    // A round accuracy on the MASUK: AKURASI_JANGGAL, but it stays VALID.
    app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-dwipa', 'MASUK', $x + ['akurasi' => 100]),
        hrisMs(2026, 10, 20, 8, 0));
    app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-dwipa', 'PULANG', $x + ['akurasi' => 12]),
        hrisMs(2026, 10, 20, 12, 0));
    // Another user on the same point 3 minutes later: KOORDINAT_KEMBAR.
    app(HrisAttendanceService::class)->recordPunch(hrisMe('u-andry'),
        hrisWajahPunch('u-andry', 'MASUK', $x + ['akurasi' => 12]),
        hrisMs(2026, 10, 20, 8, 3));

    $tgl = hrisDate(2026, 10, 20);
    $semua = app(HrisAttendanceService::class)->punches(null, $tgl, $tgl, true);
    $byUser = [];
    foreach ($semua['ketukan'] as $k) {
        $byUser[$k['user_id']][$k['arah']] = $k;
    }
    expect($byUser['u-dwipa']['MASUK']['status'])->toBe('VALID')
        ->and($byUser['u-dwipa']['MASUK']['curiga'])->toBe(['AKURASI_JANGGAL'])
        ->and($byUser['u-dwipa']['PULANG']['curiga'])->toBe(['KOORDINAT_SAMA'])
        ->and($byUser['u-andry']['MASUK']['curiga'])->toBe(['KOORDINAT_KEMBAR']);

    // Without the HR flag the same rows show nothing.
    $biasa = app(HrisAttendanceService::class)->punches(null, $tgl, $tgl, false);
    foreach ($biasa['ketukan'] as $k) {
        expect($k['curiga'])->toBe([]);
    }
});

it('hides curiga from crew over HTTP', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    $x = ['lat' => -6.2005, 'lng' => 106.8005];
    app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-dwipa', 'MASUK', $x + ['akurasi' => 100]),
        hrisMs(2026, 10, 20, 8, 0));
    $tgl = hrisDate(2026, 10, 20);

    $crew = test()->getJson("/api/v1/hris/punches?from={$tgl}&to={$tgl}",
        ['Authorization' => 'Bearer '.hrisToken('u-dwipa')])->assertOk()->json('data.ketukan');
    expect($crew)->not->toBe([]);
    foreach ($crew as $k) {
        expect($k)->toHaveKey('curiga')->and($k['curiga'])->toBe([]);
    }
});

it('shows curiga to HR over HTTP', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    $x = ['lat' => -6.2005, 'lng' => 106.8005];
    app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-dwipa', 'MASUK', $x + ['akurasi' => 100]),
        hrisMs(2026, 10, 20, 8, 0));
    $tgl = hrisDate(2026, 10, 20);

    hrisMakeHr('u-admin');
    $hr = test()->getJson("/api/v1/hris/punches?from={$tgl}&to={$tgl}",
        ['Authorization' => 'Bearer '.hrisToken('u-admin')])->assertOk()->json('data.ketukan');
    expect(collect($hr)->firstWhere('user_id', 'u-dwipa')['curiga'])->toBe(['AKURASI_JANGGAL']);
});

it('serves the clock-out goal in me/today', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    $tgl = hrisToday();
    [$y, $m, $d] = array_map(intval(...), explode('-', $tgl));
    hrisPlantShift('u-dwipa', $tgl, 'PAGI', '08:00', '17:00');
    app(HrisAttendanceService::class)->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-dwipa'), hrisMs($y, $m, $d, 8, 0));

    $r = app(HrisAttendanceService::class)->meToday(hrisMe('u-dwipa'));

    expect($r['targetMenit'])->toBe(540)->and($r['bolehPulang'])->toBe('17:00');
    expect($r['masuk']['telat_menit'])->toBe(0);

    hrisClearShift('u-dwipa', $tgl);
});

it('degrades when hris_ketukan.info is missing', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'PAGI', '08:00', '17:00');
    $svc = app(HrisAttendanceService::class);
    // Pretend the owner has not applied the migration yet: the column check
    // is cached per request, so forcing it covers the whole punch + shape.
    $prop = new ReflectionProperty(HrisAttendanceService::class, 'kolomInfo');
    $prop->setValue($svc, false);

    $out = $svc->recordPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 20, 8, 10));

    expect($out['punch']['status'])->toBe('VALID');
    expect(HrisKetukan::query()->find($out['punch']['id'])->getAttribute('info'))->toBeNull();
    expect($out['punch'])->toMatchArray(['telat_menit' => 5, 'target_menit' => 540, 'curiga' => []]);

    hrisClearShift('u-dwipa', hrisDate(2026, 10, 20));
});
