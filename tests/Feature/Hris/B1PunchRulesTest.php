<?php

use App\Modules\Hris\Services\PunchRules;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

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

it('orders the queue reasons: face and TANPA_MASUK beat the roster', function () {
    $shift = ['kode' => 'PAGI', 'mulai' => '08:00', 'selesai' => '17:00', 'sumber' => 'JADWAL', 'libur' => 0];
    // (dalamArea, shift, wajahHalangi, wajahTerdaftar, tanpaMasuk, tanpaShiftBoleh, luarWaktu)
    $alasan = fn (...$a) => PunchRules::tentukanAlasan(...$a);

    // Outside beats everything, even a face mismatch.
    expect($alasan(false, $shift, true, true, false, false, false))->toBe('LUAR_AREA');
    // An enrolled face that does NOT match is fraud suspicion: ahead of the
    // roster, and ahead of the roster-unreadable short-circuit below, so a
    // stranger's punch never passes VALID while Jadwal is down.
    expect($alasan(true, $shift, true, true, false, false, false))->toBe('WAJAH');
    expect($alasan(true, false, true, true, false, false, false))->toBe('WAJAH');
    // A PULANG without a MASUK queues even when the roster cannot be read.
    expect($alasan(true, false, false, false, true, false, false))->toBe('TANPA_MASUK');
    // An unreadable roster records without queuing.
    expect($alasan(true, false, false, false, false, false, false))->toBe('');
    // Genuinely unscheduled queues — unless allowed.
    expect($alasan(true, null, false, false, false, false, false))->toBe('TANPA_SHIFT');
    expect($alasan(true, null, false, false, false, true, false))->toBe('');
    // The person's own day off queues, even inside the shift hours.
    expect($alasan(true, ['libur' => 1] + $shift, false, false, false, false, false))->toBe('HARI_LIBUR');
    // Outside the time window queues.
    expect($alasan(true, $shift, false, false, false, false, true))->toBe('LUAR_SHIFT');
    // The unenrolled queue last, as an admin chore.
    expect($alasan(true, $shift, true, false, false, false, false))->toBe('WAJAH_KOSONG');
    // Clean punches pass.
    expect($alasan(true, $shift, false, true, false, false, false))->toBe('')
        ->and($alasan(true, $shift, false, false, false, false, false))->toBe('');
});

// ------------------------------------------------------------ area + accuracy (A13)

it('needs distance within radius AND accuracy within 1..akurasiMaks', function () {
    // Inside radius, good fix: inside.
    expect(PunchRules::dalamArea(42, 120, 12, 100))->toBeTrue();
    // Too far: outside, whatever the fix.
    expect(PunchRules::dalamArea(121, 120, 12, 100))->toBeFalse();
    // Missing or zero accuracy: outside (no jarak-akurasi slack).
    expect(PunchRules::dalamArea(0, 120, 0, 100))->toBeFalse()
        ->and(PunchRules::dalamArea(42, 120, null, 100))->toBeFalse()
        ->and(PunchRules::dalamArea(42, 120, 'buram', 100))->toBeFalse();
    // Worse than the ceiling: outside.
    expect(PunchRules::dalamArea(42, 120, 101, 100))->toBeFalse()
        ->and(PunchRules::dalamArea(42, 120, 100, 100))->toBeTrue();
});

// ------------------------------------------------------------ MASUK window (A20, A21)

it('holds MASUK in [mulai-awalMenit, selesai], overnight-aware', function () {
    // 08:00–17:00, 180 early: 05:00 in, 04:59 out, 17:00 in, 17:01 out.
    expect(PunchRules::masukDalamJendela(5 * 60, '08:00', '17:00', 180))->toBeTrue()
        ->and(PunchRules::masukDalamJendela(5 * 60 - 1, '08:00', '17:00', 180))->toBeFalse()
        ->and(PunchRules::masukDalamJendela(17 * 60, '08:00', '17:00', 180))->toBeTrue()
        ->and(PunchRules::masukDalamJendela(17 * 60 + 1, '08:00', '17:00', 180))->toBeFalse();
    // 18:00–02:00, 180 early: 15:00 in, 14:59 out, 02:00 in, 02:01 out.
    expect(PunchRules::masukDalamJendela(15 * 60, '18:00', '02:00', 180))->toBeTrue()
        ->and(PunchRules::masukDalamJendela(15 * 60 - 1, '18:00', '02:00', 180))->toBeFalse()
        ->and(PunchRules::masukDalamJendela(2 * 60, '18:00', '02:00', 180))->toBeTrue()
        ->and(PunchRules::masukDalamJendela(2 * 60 + 1, '18:00', '02:00', 180))->toBeFalse();
    expect(PunchRules::masukDalamJendela(9 * 60, '', '', 180))->toBeFalse();
});

it('counts telat_menit past mulai + toleransiTelat, overnight-aware', function () {
    expect(PunchRules::telatMenit(8 * 60, '08:00', 5))->toBe(0)
        ->and(PunchRules::telatMenit(8 * 60 + 5, '08:00', 5))->toBe(0)
        ->and(PunchRules::telatMenit(8 * 60 + 6, '08:00', 5))->toBe(1)
        ->and(PunchRules::telatMenit(7 * 60, '08:00', 5))->toBe(0);
    // Night shift 18:00–02:00: 19:00 is 55 late, 17:00 is early.
    expect(PunchRules::telatMenit(19 * 60, '18:00', 5))->toBe(55)
        ->and(PunchRules::telatMenit(17 * 60, '18:00', 5))->toBe(0);
});

// ------------------------------------------------------------ target duration (A22)

it('resolves the work-duration target: JADWAL, person, BAWAAN, default', function () {
    $kerja = ['default' => 540, 'orang' => ['u-dwipa' => 480]];
    // The Jadwal snapshot wins outright (overnight +1440).
    expect(PunchRules::targetDurasiMenit('JADWAL', '08:00', '17:00', $kerja, 'u-dwipa'))->toBe(540)
        ->and(PunchRules::targetDurasiMenit('JADWAL', '18:00', '02:00', $kerja, 'u-andry'))->toBe(480);
    // Without a Jadwal snapshot the per-person override applies.
    expect(PunchRules::targetDurasiMenit('BAWAAN', '13:00', '22:00', $kerja, 'u-dwipa'))->toBe(480)
        ->and(PunchRules::targetDurasiMenit('NONE', '', '', $kerja, 'u-dwipa'))->toBe(480);
    // Otherwise the BAWAAN snapshot, else the default.
    expect(PunchRules::targetDurasiMenit('BAWAAN', '13:00', '22:00', $kerja, 'u-andry'))->toBe(540)
        ->and(PunchRules::targetDurasiMenit('NONE', '', '', $kerja, 'u-andry'))->toBe(540)
        ->and(PunchRules::targetDurasiMenit(null, '', '', $kerja, 'u-andry'))->toBe(540);
});

it('measures PULANG against target + pulangLewatMenit', function () {
    // Short of target: early but valid.
    expect(PunchRules::nilaiPulang(240, 540, 180))
        ->toBe(['terlambat' => false, 'pulang_awal' => true, 'kurang_menit' => 300]);
    // On target: clean.
    expect(PunchRules::nilaiPulang(540, 540, 180))
        ->toBe(['terlambat' => false, 'pulang_awal' => false, 'kurang_menit' => 0]);
    // Past target but within grace: clean.
    expect(PunchRules::nilaiPulang(720, 540, 180))
        ->toBe(['terlambat' => false, 'pulang_awal' => false, 'kurang_menit' => 0]);
    // Past target + grace: out of policy.
    expect(PunchRules::nilaiPulang(721, 540, 180))
        ->toBe(['terlambat' => true, 'pulang_awal' => false, 'kurang_menit' => 0]);
});

// ------------------------------------------------------------ suspicion flags (A16)

it('flags suspicious punches without ever queuing them', function () {
    $lokasi = ['lat' => -6.2, 'lng' => 106.8];
    // A clean punch: nothing.
    expect(PunchRules::tandaCuriga(-6.2005, 106.8005, 12, $lokasi, ['lat' => -6.201, 'lng' => 106.801], false))->toBe([]);
    // Identical coordinates to the person's previous punch.
    expect(PunchRules::tandaCuriga(-6.2, 106.8, 12, null, ['lat' => -6.2, 'lng' => 106.8], false))
        ->toBe(['KOORDINAT_SAMA']);
    // Exactly on the location centre.
    expect(PunchRules::tandaCuriga(-6.2, 106.8, 12, $lokasi, null, false))->toBe(['TITIK_PUSAT']);
    // Round or tiny accuracy.
    expect(PunchRules::tandaCuriga(-6.2005, 106.8005, 100, null, null, false))->toBe(['AKURASI_JANGGAL'])
        ->and(PunchRules::tandaCuriga(-6.2005, 106.8005, 1, null, null, false))->toBe(['AKURASI_JANGGAL'])
        ->and(PunchRules::tandaCuriga(-6.2005, 106.8005, 0, null, null, false))->toBe(['AKURASI_JANGGAL']);
    // Another user sent the same point within ±5 minutes.
    expect(PunchRules::tandaCuriga(-6.2005, 106.8005, 12, null, null, true))->toBe(['KOORDINAT_KEMBAR']);
});
