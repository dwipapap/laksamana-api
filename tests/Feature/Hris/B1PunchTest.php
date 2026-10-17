<?php

use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisCalendar;
use App\Modules\Jadwal\Services\JadwalService;
use App\Support\JsonDoc;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/*
 * Punches: VALID inside the rules, MENUNGGU with one reason otherwise; a
 * repeated MASUK answers duplikat, a repeated PULANG overwrites. Time
 * travels through recordPunch($me, $in, $nowMs) on fixed WIB instants so
 * shift paths are deterministic; HTTP covers wiring and validation.
 * One HTTP identity per test; the rest goes through the service directly.
 */

function hrisPunch(array $me, array $in, ?int $nowMs = null): array
{
    return app(HrisAttendanceService::class)->recordPunch($me, $in, $nowMs);
}

/** Remove the person's default shift (shiftKru) so "no shift" is guaranteed. */
function hrisClearDefaultShift(string $uid): void
{
    $jadwal = app(JadwalService::class);
    $data = JsonDoc::toArray($jadwal->setting());
    if (isset($data['shiftKru']) && is_array($data['shiftKru'])) {
        unset($data['shiftKru'][$uid]);
        $jadwal->saveSetting($data, 'test');
    }
}

/** Give the person a default shift (shiftKru) for the fallback path. */
function hrisSetDefaultShift(string $uid, string $kode, string $m, string $s): void
{
    $jadwal = app(JadwalService::class);
    $data = JsonDoc::toArray($jadwal->setting());
    $shifts = is_array($data['shifts'] ?? null) ? $data['shifts'] : [];
    $shifts[$kode] = ['m' => $m, 's' => $s];
    $data['shifts'] = $shifts;
    $kru = is_array($data['shiftKru'] ?? null) ? $data['shiftKru'] : [];
    $kru[$uid] = $kode;
    $data['shiftKru'] = $kru;
    $jadwal->saveSetting($data, 'test');
}

it('serves the punch-screen bootstrap', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    $t = hrisToken('u-dwipa');

    $r = test()->getJson('/api/v1/hris/me/today', ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    expect($r)->toHaveKeys(['siapa', 'tgl', 'waktuServer', 'jam', 'shift', 'masuk', 'pulang', 'wajahTerdaftar', 'lokasi', 'lingkup',
        'aturan', 'targetMenit', 'bolehPulang'])
        ->and($r['siapa']['id'])->toBe('u-dwipa')
        ->and($r['tgl'])->toBe(hrisToday())
        ->and($r['aturan'])->toMatchArray(['akurasiMaks' => 100, 'awalMenit' => 180,
            'pulangLewatMenit' => 180, 'toleransiTelat' => 5])
        ->and($r['targetMenit'])->toBeNull()
        ->and($r['bolehPulang'])->toBeNull();
});

it('punches VALID with no setup at all over HTTP', function () {
    // No locations registered = not set up yet (no LUAR_AREA); shift checks
    // allowed; the enrolled face must match.
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');

    $r = test()->postJson('/api/v1/hris/punches',
        ['arah' => 'MASUK'] + hrisWajahPunch('u-dwipa'),
        ['Authorization' => 'Bearer '.$t])->assertCreated()->json();

    expect($r['data']['duplikat'])->toBeFalse();
    expect($r['data']['punch'])->toMatchArray(['arah' => 'MASUK', 'status' => 'VALID', 'alasan_antrean' => []]);
    expect($r['data']['punch'])->toHaveKeys(['telat_menit', 'pulang_awal', 'kurang_menit',
        'target_menit', 'durasi_menit', 'curiga']);
    expect($r['data']['punch']['curiga'])->toBe([]);
});

it('rejects bad directions and reasonless out-of-policy punches', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/punches', ['arah' => 'DATANG'], $h)
        ->assertStatus(422)->assertJsonPath('error.message', 'Arah absen harus MASUK atau PULANG.');
    // Unenrolled and out of policy without a reason: 422 ...
    test()->postJson('/api/v1/hris/punches', ['arah' => 'MASUK'], $h)
        ->assertStatus(422)->assertJsonPath('error.message', 'Absen ini di luar ketentuan, jadi harus disertai alasan untuk diajukan.');
    // ... with a reason: queued as WAJAH_KOSONG.
    $r = test()->postJson('/api/v1/hris/punches', ['arah' => 'MASUK', 'alasan' => 'belum daftar'], $h)
        ->assertCreated()->json();
    expect($r['data']['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH_KOSONG']]);
});

it('queues LUAR_AREA far from every location', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);

    $out = hrisPunch(hrisMe('u-dwipa'),
        ['arah' => 'MASUK', 'lat' => -6.0, 'lng' => 107.5, 'akurasi' => 12, 'alasan' => 'dinas luar'],
        hrisMs(2026, 10, 20, 9, 0));

    expect($out['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['LUAR_AREA']]);
    expect($out['punch']['curiga'])->toBe([]);
});

it('queues TANPA_SHIFT when genuinely unscheduled', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    $tgl = hrisDate(2026, 10, 20);
    hrisClearShift('u-dwipa', $tgl);
    hrisClearDefaultShift('u-dwipa');

    expect(app(HrisAttendanceService::class)->shiftFor('u-dwipa', $tgl))->toBeNull();

    $out = hrisPunch(hrisMe('u-dwipa'), ['arah' => 'MASUK', 'alasan' => 'jadwal kosong'],
        hrisMs(2026, 10, 20, 9, 0));

    expect($out['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['TANPA_SHIFT']]);
});

it('surfaces WAJAH ahead of TANPA_SHIFT on a mismatch', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    $tgl = hrisDate(2026, 10, 20);
    hrisClearShift('u-dwipa', $tgl);
    hrisClearDefaultShift('u-dwipa');

    // Same unscheduled setup as the TANPA_SHIFT case, but the face belongs
    // to someone else: HR must see suspicion, not a scheduling issue.
    $out = hrisPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-andry', 'MASUK', ['alasan' => 'wajah orang lain']),
        hrisMs(2026, 10, 20, 9, 0));
    expect($out['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH']]);
});

it('holds MASUK to the shift window and stamps telat_menit', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-dwipa');
    $me = hrisMe('u-dwipa');

    // 07:55 against 08:00–17:00 is early but inside [05:00, 17:00]: VALID, on time.
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'PAGI', '08:00', '17:00');
    $pagi = hrisPunch($me, hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 20, 7, 55));
    expect($pagi['punch'])->toMatchArray(['status' => 'VALID', 'shift_kode' => 'PAGI']);
    expect($pagi['punch']['telat_menit'])->toBe(0);

    // 08:10 is late inside the shift: still VALID, stamped telat_menit 5.
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 21), 'PAGI', '08:00', '17:00');
    $telat = hrisPunch($me, hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 21, 8, 10));
    expect($telat['punch'])->toMatchArray(['status' => 'VALID']);
    expect($telat['punch']['telat_menit'])->toBe(5);

    // 04:59 is earlier than the window, 17:01 is past selesai: LUAR_SHIFT.
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 22), 'PAGI', '08:00', '17:00');
    $kepagian = hrisPunch($me, hrisWajahPunch('u-dwipa', 'MASUK', ['alasan' => 'lembur pagi']),
        hrisMs(2026, 10, 22, 4, 59));
    expect($kepagian['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['LUAR_SHIFT']]);

    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 23), 'PAGI', '08:00', '17:00');
    $kesorean = hrisPunch($me, hrisWajahPunch('u-dwipa', 'MASUK', ['alasan' => 'datang sore']),
        hrisMs(2026, 10, 23, 17, 1));
    expect($kesorean['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['LUAR_SHIFT']]);

    foreach ([20, 21, 22, 23] as $d) {
        hrisClearShift('u-dwipa', hrisDate(2026, 10, $d));
    }
});

it('falls back to the default shift when the roster has no cell', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisEnrolOnce('u-andry');
    $tgl = hrisDate(2026, 10, 21);
    hrisClearShift('u-andry', $tgl);
    hrisSetDefaultShift('u-andry', 'SIANG', '13:00', '22:00');

    $shift = app(HrisAttendanceService::class)->shiftFor('u-andry', $tgl, 14 * 60);
    expect($shift)->toMatchArray(['kode' => 'SIANG', 'sumber' => 'BAWAAN']);

    $out = hrisPunch(hrisMe('u-andry'), hrisWajahPunch('u-andry'), hrisMs(2026, 10, 21, 14, 0));
    expect($out['punch'])->toMatchArray(['status' => 'VALID', 'shift_sumber' => 'BAWAAN']);
});

it('queues WAJAH on mismatch and WAJAH_KOSONG when never enrolled', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');

    $salah = hrisPunch(hrisMe('u-dwipa'),
        hrisWajahPunch('u-andry', 'MASUK', ['alasan' => 'kamera buram']),
        hrisMs(2026, 10, 20, 9, 0));
    expect($salah['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH']]);

    $kosong = hrisPunch(hrisMe('u-andry'), ['arah' => 'MASUK', 'alasan' => 'belum daftar'],
        hrisMs(2026, 10, 20, 9, 0));
    expect($kosong['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH_KOSONG']]);
});

it('answers a repeated MASUK as duplikat', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    $me = hrisMe('u-dwipa');
    $now = hrisMs(2026, 10, 22, 9, 0);

    $satu = hrisPunch($me, hrisWajahPunch('u-dwipa'), $now);
    expect($satu['duplikat'])->toBeFalse();
    $dua = hrisPunch($me, hrisWajahPunch('u-dwipa'), $now + 1000);
    expect($dua['duplikat'])->toBeTrue()->and($dua['punch']['id'])->toBe($satu['punch']['id']);
    expect(HrisKetukan::query()->where('user_id', 'u-dwipa')
        ->where('tanggal_kerja', hrisDate(2026, 10, 22))->where('arah', 'MASUK')->count())->toBe(1);
});

it('lets a repeated PULANG overwrite and belong to the MASUK date', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisEnrolOnce('u-dwipa');
    $me = hrisMe('u-dwipa');

    $masuk = hrisPunch($me, hrisWajahPunch('u-dwipa'), hrisMs(2026, 10, 20, 22, 0));
    expect($masuk['punch']['status'])->toBe('VALID');
    $satu = hrisPunch($me, hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 21, 0, 30));
    expect($satu['punch']['tanggal_kerja'])->toBe(hrisDate(2026, 10, 20));
    // 150 of 540 minutes: VALID but early.
    expect($satu['punch'])->toMatchArray(['status' => 'VALID', 'pulang_awal' => true]);
    expect($satu['punch']['kurang_menit'])->toBe(390);
    $dua = hrisPunch($me, hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 10, 21, 1, 0));
    expect($dua['duplikat'])->toBeFalse();
    expect(HrisKetukan::query()->where('user_id', 'u-dwipa')
        ->where('tanggal_kerja', hrisDate(2026, 10, 20))->where('arah', 'PULANG')->count())->toBe(1);
    expect($dua['punch']['waktu'])->toBe(hrisMs(2026, 10, 21, 1, 0));
    expect($dua['punch']['kurang_menit'])->toBe(360);
});

it('knows no holiday until HR enters one', function () {
    expect(app(HrisCalendar::class)->isHoliday('2026-12-25'))->toBeFalse();
});
