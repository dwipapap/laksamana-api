<?php

use App\Modules\Hris\Services\PunchRules;
use Carbon\CarbonImmutable;

/*
 * PunchRules is pure (no DB, no clock): every case runs on fixed inputs.
 */

// ------------------------------------------------------------ time (WIB)

it('renders server time as WIB', function () {
    // 2026-10-20 00:30 UTC = 07:30 WIB.
    $ms = (int) (CarbonImmutable::create(2026, 10, 20, 0, 30, 0, 'UTC')->timestamp * 1000);
    expect(PunchRules::jamWib($ms))->toBe('07:30')
        ->and(PunchRules::tglWib($ms))->toBe('2026-10-20')
        ->and(PunchRules::menitWib($ms))->toBe(7 * 60 + 30);
});

it('parses HH:MM strictly', function () {
    expect(PunchRules::jamKeMenit('08:00'))->toBe(480)
        ->and(PunchRules::jamKeMenit('8:00'))->toBe(480)
        ->and(PunchRules::jamKeMenit('24:00'))->toBe(-1)
        ->and(PunchRules::jamKeMenit('08:60'))->toBe(-1)
        ->and(PunchRules::jamKeMenit('pagi'))->toBe(-1);
});

it('validates dates strictly', function () {
    expect(PunchRules::tglValid('2026-10-20'))->toBe('2026-10-20')
        ->and(PunchRules::tglValid('2026-13-45'))->toBe('')
        ->and(PunchRules::tglValid('2026-02-30'))->toBe('')
        ->and(PunchRules::tglValid('20-10-2026'))->toBe('');
});

// ------------------------------------------------------------ location

it('measures haversine distance', function () {
    // ~111 m per 0.001 degree of latitude; same point is 0.
    expect(PunchRules::haversineMeters(-6.2, 106.8, -6.2, 106.8))->toBe(0);
    $j = PunchRules::haversineMeters(-6.2, 106.8, -6.199, 106.8);
    expect($j)->toBeGreaterThan(90)->toBeLessThan(135);
});

it('clamps the radius to 30–2000 m', function () {
    expect(PunchRules::clampRadius(0))->toBe(30)
        ->and(PunchRules::clampRadius(10))->toBe(30)
        ->and(PunchRules::clampRadius(120))->toBe(120)
        ->and(PunchRules::clampRadius(5000))->toBe(2000);
});

it('skips 0,0 placeholder locations', function () {
    $locs = [
        ['id' => 'a', 'lat' => 0.0, 'lng' => 0.0, 'radius' => 120],
        ['id' => 'b', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120],
    ];
    $dekat = PunchRules::nearestLocation(-6.2, 106.8, $locs);
    expect($dekat['lokasi']['id'])->toBe('b')->and($dekat['jarak'])->toBe(0);
    expect(PunchRules::nearestLocation(-6.2, 106.8, [['id' => 'a', 'lat' => 0.0, 'lng' => 0.0, 'radius' => 120]]))->toBeNull()
        ->and(PunchRules::nearestLocation(-6.2, 106.8, []))->toBeNull();
});

// ------------------------------------------------------------ shifts

it('holds the shift window with tolerances', function () {
    // 08:00–17:00 ±(60, 180): 07:00 in, 06:59 out, 20:00 in, 20:01 out.
    expect(PunchRules::dalamRentangShift(7 * 60, '08:00', '17:00', 60, 180))->toBeTrue()
        ->and(PunchRules::dalamRentangShift(7 * 60 - 1, '08:00', '17:00', 60, 180))->toBeFalse()
        ->and(PunchRules::dalamRentangShift(20 * 60, '08:00', '17:00', 60, 180))->toBeTrue()
        ->and(PunchRules::dalamRentangShift(20 * 60 + 1, '08:00', '17:00', 60, 180))->toBeFalse();
});

it('spans overnight shifts past midnight', function () {
    // 18:00–02:00 ±(60, 180): 01:00 in, 05:00 out (02:00 + 180).
    expect(PunchRules::dalamRentangShift(60, '18:00', '02:00', 60, 180))->toBeTrue()
        ->and(PunchRules::dalamRentangShift(5 * 60, '18:00', '02:00', 60, 180))->toBeTrue()
        ->and(PunchRules::dalamRentangShift(5 * 60 + 1, '18:00', '02:00', 60, 180))->toBeFalse()
        ->and(PunchRules::dalamRentangShift(12 * 60, '18:00', '02:00', 60, 180))->toBeFalse();
});

it('keeps a PULANG within 18 h on the MASUK date', function () {
    expect(PunchRules::workDateForPulang('2026-10-20', '2026-10-21'))->toBe('2026-10-20')
        ->and(PunchRules::workDateForPulang(null, '2026-10-21'))->toBe('2026-10-21')
        ->and(PunchRules::workDateForPulang('', '2026-10-21'))->toBe('2026-10-21');
});

// ------------------------------------------------------------ queue reasons

it('orders the queue reasons like the legacy module', function () {
    $shift = ['kode' => 'PAGI', 'mulai' => '08:00', 'selesai' => '17:00', 'sumber' => 'JADWAL', 'libur' => 0];
    $alasan = fn (...$a) => PunchRules::tentukanAlasan(...$a);

    // Outside beats everything, even a face mismatch.
    expect($alasan(false, $shift, true, true, true, false, false))->toBe('LUAR_AREA');
    // An unreadable roster records without queuing.
    expect($alasan(true, false, false, true, true, false, false))->toBe('');
    // Genuinely unscheduled queues — unless allowed.
    expect($alasan(true, null, false, false, false, false, false))->toBe('TANPA_SHIFT');
    expect($alasan(true, null, false, false, false, true, false))->toBe('');
    // A holiday worked queues, even inside the shift.
    expect($alasan(true, $shift, true, false, false, false, true))->toBe('HARI_LIBUR');
    // Outside the shift window queues.
    expect($alasan(true, $shift, false, false, false, false, false))->toBe('LUAR_SHIFT');
    // Face mismatch queues as suspicion; the unenrolled as admin chore.
    expect($alasan(true, $shift, true, true, true, false, false))->toBe('WAJAH');
    expect($alasan(true, $shift, true, true, false, false, false))->toBe('WAJAH_KOSONG');
    // Clean punches pass.
    expect($alasan(true, $shift, true, false, true, false, false))->toBe('')
        ->and($alasan(true, $shift, true, false, false, false, false))->toBe('');
});
