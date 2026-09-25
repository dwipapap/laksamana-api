<?php

use App\Modules\Absensi\Services\AbsensiService as A;

/*
 * The punch rules as pure functions on fixed inputs — no DB, no clock, so
 * they run in the DB-free Unit suite (CI) and pin the legacy behaviour:
 * face distance, GPS radius, the night-shift work date, the six queue
 * reasons in order, shift ranges past midnight, and the recap math
 * (including the morning-shift past-midnight checkout).
 */

it('measures earth distance in whole metres', function () {
    expect(A::jarakMeter(-6.2, 106.8, -6.2, 106.8))->toBe(0)
        ->and(A::jarakMeter(-6.2, 106.8, -6.201, 106.8))->toBe(111)
        ->and(A::jarakMeter(-6.2, 106.8, -6.21, 106.8))->toBe(1112)
        // one block east-west at Jakarta's latitude is shorter than north-south
        ->and(A::jarakMeter(-6.2, 106.8, -6.2, 106.81))->toBe(1105);
});

it('clamps the GPS radius to 30–2000 m', function () {
    expect(A::clampRadius(120))->toBe(120)
        ->and(A::clampRadius(10))->toBe(30)
        ->and(A::clampRadius(0))->toBe(30)
        ->and(A::clampRadius(5000))->toBe(2000)
        ->and(A::clampRadius(2000))->toBe(2000);
});

it('finds the nearest location and skips unpinned ones', function () {
    $locs = [
        ['id' => 'kosong', 'lat' => 0.0, 'lng' => 0.0, 'radius' => 120],
        ['id' => 'kantor', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120],
        ['id' => 'gudang', 'lat' => -6.3, 'lng' => 106.8, 'radius' => 200],
    ];
    $dekat = A::lokasiTerdekat(-6.2005, 106.8, $locs);
    expect($dekat['lokasi']['id'])->toBe('kantor')
        ->and($dekat['jarak'])->toBe(56)
        ->and(A::lokasiTerdekat(0, 0, [['id' => 'x', 'lat' => 0.0, 'lng' => 0.0, 'radius' => 120]]))->toBeNull()
        ->and(A::lokasiTerdekat(1, 1, []))->toBeNull();
});

it('keeps WIB time on fixed stamps', function () {
    // 01:00 UTC = 08:00 WIB, same date
    $ms = gmmktime(1, 0, 0, 9, 25, 2026) * 1000;
    expect(A::jamWib($ms))->toBe('08:00')
        ->and(A::tglWib($ms))->toBe('2026-09-25')
        ->and(A::menitWib($ms))->toBe(480);
    // 17:00 UTC = midnight WIB: the work date rolls over
    $ms = gmmktime(17, 0, 0, 9, 25, 2026) * 1000;
    expect(A::jamWib($ms))->toBe('00:00')
        ->and(A::tglWib($ms))->toBe('2026-09-26')
        ->and(A::menitWib($ms))->toBe(0);
});

it('parses HH:MM to minutes', function () {
    expect(A::jamKeMenit('08:00'))->toBe(480)
        ->and(A::jamKeMenit('8:05'))->toBe(485)
        ->and(A::jamKeMenit('23:59'))->toBe(1439)
        ->and(A::jamKeMenit(''))->toBe(-1)
        ->and(A::jamKeMenit('pagi'))->toBe(-1);
});

it('judges inside/outside a shift with tolerances, past midnight too', function () {
    // 08:00–17:00 with 60 early / 180 late: window 07:00–20:00
    expect(A::dalamRentangShift(480, '08:00', '17:00', 60, 180))->toBeTrue()
        ->and(A::dalamRentangShift(420, '08:00', '17:00', 60, 180))->toBeTrue()
        ->and(A::dalamRentangShift(419, '08:00', '17:00', 60, 180))->toBeFalse()
        ->and(A::dalamRentangShift(1200, '08:00', '17:00', 60, 180))->toBeTrue()
        ->and(A::dalamRentangShift(1201, '08:00', '17:00', 60, 180))->toBeFalse();
    // 22:00–06:00: 02:00 is inside, 10:00 is outside
    expect(A::dalamRentangShift(120, '22:00', '06:00', 60, 180))->toBeTrue()
        ->and(A::dalamRentangShift(600, '22:00', '06:00', 60, 180))->toBeFalse()
        ->and(A::dalamRentangShift(1380, '22:00', '06:00', 60, 180))->toBeTrue();
    // unreadable clocks never match
    expect(A::dalamRentangShift(480, '', '', 60, 180))->toBeFalse();
});

it('measures distance to a shift for the multi-shift DW pick', function () {
    expect(A::jarakKeShift(480, '08:00', '17:00'))->toBe(0)
        ->and(A::jarakKeShift(400, '08:00', '17:00'))->toBe(80)
        ->and(A::jarakKeShift(1100, '08:00', '17:00'))->toBe(80);
    // 02:00 is inside the 22:00–06:00 night shift, not 22 h away from it
    expect(A::jarakKeShift(120, '22:00', '06:00'))->toBe(0)
        ->and(A::jarakKeShift(600, '22:00', '06:00'))->toBe(240);
});

it('matches faces by euclidean distance against the threshold', function () {
    $nol = array_fill(0, 128, 0.0);
    expect(A::faceDistance($nol, $nol))->toBe(0.0);

    $geser = array_fill(0, 128, 0.1);
    expect(A::faceDistance($nol, $geser))->toBeGreaterThan(1.13)->toBeLessThan(1.14);

    // same face: ok and enrolled
    $m = A::faceMatch($nol, $nol, 0.45);
    expect($m)->toMatchArray(['skor' => 0.0, 'ok' => true, 'terdaftar' => true]);

    // a stranger: enrolled, but not ok
    $m = A::faceMatch($nol, array_fill(0, 128, 0.9), 0.45);
    expect($m['terdaftar'])->toBeTrue()->and($m['ok'])->toBeFalse();

    // never enrolled: nothing to match against
    $m = A::faceMatch(null, $nol, 0.45);
    expect($m)->toMatchArray(['skor' => 1.0, 'ok' => false, 'terdaftar' => false]);

    // a broken stored print counts as unenrolled, a missing probe as mismatch
    expect(A::faceMatch([1.0, 2.0], $nol, 0.45)['terdaftar'])->toBeFalse()
        ->and(A::faceMatch($nol, null, 0.45))->toMatchArray(['ok' => false, 'terdaftar' => true]);
});

it('orders the six queue reasons exactly like the legacy punch', function () {
    $set = A::defaultSetting(); // tanpaShiftBoleh off, wajahWajib off
    $shift = ['kode' => 'P', 'mulai' => '08:00', 'selesai' => '17:00', 'sumber' => 'ROSTER', 'libur' => 0];

    // outside beats everything, even a perfect face and shift
    expect(A::tentukanSebab(false, $shift, true, false, true, $set))->toBe('LUAR_AREA');
    // unreadable roster: no reason at all — never a fake queue…
    expect(A::tentukanSebab(true, false, false, false, true, $set))->toBe('');
    // …but area and face still apply while the roster is dark
    expect(A::tentukanSebab(false, false, false, false, true, $set))->toBe('LUAR_AREA');
    expect(A::tentukanSebab(true, false, false, true, true, $set))->toBe('WAJAH');
    // unscheduled, off day, out of shift hours
    expect(A::tentukanSebab(true, null, false, false, false, $set))->toBe('TANPA_SHIFT');
    expect(A::tentukanSebab(true, ['libur' => 1] + $shift, false, false, false, $set))->toBe('HARI_LIBUR');
    expect(A::tentukanSebab(true, $shift, false, false, false, $set))->toBe('LUAR_SHIFT');
    // face: enrolled-but-strange is suspicion, never-enrolled is a chore
    expect(A::tentukanSebab(true, $shift, true, true, true, $set))->toBe('WAJAH');
    expect(A::tentukanSebab(true, $shift, true, true, false, $set))->toBe('WAJAH_KOSONG');
    // a clean punch has no reason
    expect(A::tentukanSebab(true, $shift, true, false, true, $set))->toBe('');

    // tanpaShiftBoleh on: the unscheduled may pass (face still applies)
    $longgar = ['tanpaShiftBoleh' => true] + $set;
    expect(A::tentukanSebab(true, null, false, false, false, $longgar))->toBe('');
    expect(A::tentukanSebab(true, null, false, true, true, $longgar))->toBe('WAJAH');
});

it('resolves the PULANG work date across midnight', function () {
    // night shift: MASUK yesterday evening, punching out after midnight
    // counts for yesterday
    expect(A::workDateForPulang(['tgl' => '2026-09-24', 'waktu' => 1], '2026-09-25'))->toBe('2026-09-24');
    // no MASUK in the last 18 h: today
    expect(A::workDateForPulang(null, '2026-09-25'))->toBe('2026-09-25');
});

function hari(string $jam, string $m, string $s, int $waktu, string $status = 'VALID'): array
{
    return ['jam' => $jam, 'm' => $m, 's' => $s, 'waktu' => $waktu, 'status' => $status];
}

it('computes lateness with tolerance and overtime with a floor', function () {
    $set = A::defaultSetting(); // toleransi 5, lembur min 15
    $t0 = gmmktime(1, 0, 0, 9, 25, 2026) * 1000; // 08:00 WIB

    // in at 08:02, out at 17:05: on time, 543 min, no overtime (5 < 15)
    $h = A::hitungHari(hari('08:02', '08:00', '17:00', $t0 + 2 * 60000),
        hari('17:05', '08:00', '17:00', $t0 + 545 * 60000), $set);
    expect($h)->toMatchArray(['telat' => 0, 'lembur' => 0, 'cepat' => 0, 'durasi' => 543, 'lengkap' => 1]);

    // in at 08:10: 5 late; out at 18:00: 60 overtime
    $h = A::hitungHari(hari('08:10', '08:00', '17:00', $t0 + 10 * 60000),
        hari('18:00', '08:00', '17:00', $t0 + 600 * 60000), $set);
    expect($h)->toMatchArray(['telat' => 5, 'lembur' => 60, 'cepat' => 0, 'lengkap' => 1]);

    // out at 16:00: 60 early
    $h = A::hitungHari(hari('08:00', '08:00', '17:00', $t0),
        hari('16:00', '08:00', '17:00', $t0 + 480 * 60000), $set);
    expect($h)->toMatchArray(['telat' => 0, 'lembur' => 0, 'cepat' => 60, 'lengkap' => 1]);
});

it('counts a morning shift ending past midnight as overtime, not early leave', function () {
    $set = A::defaultSetting();
    $t0 = gmmktime(1, 0, 0, 9, 25, 2026) * 1000; // 08:00 WIB
    // the event night: out at 00:30 next day, 990 min after coming in
    $h = A::hitungHari(hari('08:00', '08:00', '17:00', $t0),
        hari('00:30', '08:00', '17:00', $t0 + 990 * 60000), $set);
    expect($h)->toMatchArray(['telat' => 0, 'lembur' => 450, 'cepat' => 0, 'durasi' => 990, 'lengkap' => 1]);
});

it('counts a night shift arrival and checkout on the real elapsed work', function () {
    $set = A::defaultSetting();
    $t0 = gmmktime(15, 0, 0, 9, 25, 2026) * 1000; // 22:00 WIB
    // in 22:05, out 06:30: on time, 505 min, 30 overtime
    $h = A::hitungHari(hari('22:05', '22:00', '06:00', $t0 + 5 * 60000),
        hari('06:30', '22:00', '06:00', $t0 + 510 * 60000), $set);
    expect($h)->toMatchArray(['telat' => 0, 'lembur' => 30, 'cepat' => 0, 'lengkap' => 1]);
});

it('ignores refused punches and flags the incomplete day', function () {
    $set = A::defaultSetting();
    $t0 = gmmktime(1, 0, 0, 9, 25, 2026) * 1000;
    // refused MASUK: no lateness, no duration
    $h = A::hitungHari(hari('08:40', '08:00', '17:00', $t0, 'DITOLAK'),
        hari('17:00', '08:00', '17:00', $t0 + 500 * 60000), $set);
    expect($h)->toMatchArray(['telat' => 0, 'durasi' => 0, 'lengkap' => 0]);
    // not out yet: lateness is already certain, the day incomplete
    $h = A::hitungHari(hari('08:40', '08:00', '17:00', $t0), null, $set);
    expect($h)->toMatchArray(['telat' => 35, 'durasi' => 0, 'lengkap' => 0]);
});
