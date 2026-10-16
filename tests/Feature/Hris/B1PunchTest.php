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

    expect($r)->toHaveKeys(['siapa', 'tgl', 'waktuServer', 'jam', 'shift', 'masuk', 'pulang', 'wajahTerdaftar', 'lokasi', 'lingkup'])
        ->and($r['siapa']['id'])->toBe('u-dwipa')
        ->and($r['tgl'])->toBe(hrisToday());
});

it('punches VALID with no setup at all over HTTP', function () {
    // No locations registered = not set up yet (no LUAR_AREA); shift checks
    // allowed; nobody enrolled and wajahWajib off.
    hrisSettings(['tanpaShiftBoleh' => true]);
    $t = hrisToken('u-dwipa');

    $r = test()->postJson('/api/v1/hris/punches', ['arah' => 'MASUK'],
        ['Authorization' => 'Bearer '.$t])->assertCreated()->json();

    expect($r['data']['duplikat'])->toBeFalse();
    expect($r['data']['punch'])->toMatchArray(['arah' => 'MASUK', 'status' => 'VALID', 'alasan_antrean' => []]);
});

it('rejects bad directions and reasonless out-of-policy punches', function () {
    hrisSettings(['wajahWajib' => true]); // the unenrolled always queue, whatever the shift
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/punches', ['arah' => 'DATANG'], $h)
        ->assertStatus(422)->assertJsonPath('error.message', 'Arah absen harus MASUK atau PULANG.');
    test()->postJson('/api/v1/hris/punches', ['arah' => 'MASUK'], $h)
        ->assertStatus(422)->assertJsonPath('error.message', 'Absen ini di luar ketentuan, jadi harus disertai alasan untuk diajukan.');
});

it('queues LUAR_AREA far from every location', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);

    $out = hrisPunch(hrisMe('u-dwipa'),
        ['arah' => 'MASUK', 'lat' => -6.0, 'lng' => 107.5, 'alasan' => 'dinas luar'],
        hrisMs(2026, 10, 20, 9, 0));

    expect($out['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['LUAR_AREA']]);
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
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisDescriptor(), 'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    $tgl = hrisDate(2026, 10, 20);
    hrisClearShift('u-dwipa', $tgl);
    hrisClearDefaultShift('u-dwipa');

    // Same unscheduled setup as the TANPA_SHIFT case, but the face belongs
    // to someone else: HR must see suspicion, not a scheduling issue.
    $out = hrisPunch(hrisMe('u-dwipa'),
        ['arah' => 'MASUK', 'descriptor' => hrisDescriptor(-0.5), 'model' => 'sface-2021dec-int8', 'alasan' => 'wajah orang lain'],
        hrisMs(2026, 10, 20, 9, 0));
    expect($out['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH']]);
});

it('reads the shift from Jadwal and queues LUAR_SHIFT outside it', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    $tgl = hrisDate(2026, 10, 20);
    hrisPlantShift('u-dwipa', $tgl, 'PAGI', '08:00', '17:00');

    $shift = app(HrisAttendanceService::class)->shiftFor('u-dwipa', $tgl, 9 * 60);
    expect($shift)->toMatchArray(['kode' => 'PAGI', 'sumber' => 'JADWAL', 'libur' => 0]);

    $dalam = hrisPunch(hrisMe('u-dwipa'), ['arah' => 'MASUK'], hrisMs(2026, 10, 20, 9, 0));
    expect($dalam['punch'])->toMatchArray(['status' => 'VALID', 'shift_kode' => 'PAGI']);

    // 03:00 is outside 08:00 ± 60 min (PULANG so it is not a MASUK duplicate).
    $luar = hrisPunch(hrisMe('u-dwipa'), ['arah' => 'PULANG', 'alasan' => 'pulang dini hari'],
        hrisMs(2026, 10, 20, 3, 0));
    expect($luar['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['LUAR_SHIFT']]);

    hrisClearShift('u-dwipa', $tgl);
});

it('falls back to the default shift when the roster has no cell', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    $tgl = hrisDate(2026, 10, 21);
    hrisClearShift('u-andry', $tgl);
    hrisSetDefaultShift('u-andry', 'SIANG', '13:00', '22:00');

    $shift = app(HrisAttendanceService::class)->shiftFor('u-andry', $tgl, 14 * 60);
    expect($shift)->toMatchArray(['kode' => 'SIANG', 'sumber' => 'BAWAAN']);

    $out = hrisPunch(hrisMe('u-andry'), ['arah' => 'MASUK'], hrisMs(2026, 10, 21, 14, 0));
    expect($out['punch'])->toMatchArray(['status' => 'VALID', 'shift_sumber' => 'BAWAAN']);
});

it('queues WAJAH on mismatch and WAJAH_KOSONG when wajahWajib', function () {
    hrisSettings(['tanpaShiftBoleh' => true, 'wajahWajib' => false]);
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisDescriptor(), 'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);

    $salah = hrisPunch(hrisMe('u-dwipa'),
        ['arah' => 'MASUK', 'descriptor' => hrisDescriptor(-0.5), 'model' => 'sface-2021dec-int8', 'alasan' => 'kamera buram'],
        hrisMs(2026, 10, 20, 9, 0));
    expect($salah['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH']]);

    hrisSettings(['tanpaShiftBoleh' => true, 'wajahWajib' => true]);
    $kosong = hrisPunch(hrisMe('u-andry'), ['arah' => 'MASUK', 'alasan' => 'belum daftar'],
        hrisMs(2026, 10, 20, 9, 0));
    expect($kosong['punch'])->toMatchArray(['status' => 'MENUNGGU', 'alasan_antrean' => ['WAJAH_KOSONG']]);
});

it('answers a repeated MASUK as duplikat', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    $me = hrisMe('u-dwipa');
    $now = hrisMs(2026, 10, 22, 9, 0);

    $satu = hrisPunch($me, ['arah' => 'MASUK'], $now);
    expect($satu['duplikat'])->toBeFalse();
    $dua = hrisPunch($me, ['arah' => 'MASUK'], $now + 1000);
    expect($dua['duplikat'])->toBeTrue()->and($dua['punch']['id'])->toBe($satu['punch']['id']);
    expect(HrisKetukan::query()->where('user_id', 'u-dwipa')
        ->where('tanggal_kerja', hrisDate(2026, 10, 22))->where('arah', 'MASUK')->count())->toBe(1);
});

it('lets a repeated PULANG overwrite and belong to the MASUK date', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    $me = hrisMe('u-dwipa');

    hrisPunch($me, ['arah' => 'MASUK'], hrisMs(2026, 10, 20, 22, 0));
    $satu = hrisPunch($me, ['arah' => 'PULANG'], hrisMs(2026, 10, 21, 0, 30));
    expect($satu['punch']['tanggal_kerja'])->toBe(hrisDate(2026, 10, 20));
    $dua = hrisPunch($me, ['arah' => 'PULANG'], hrisMs(2026, 10, 21, 1, 0));
    expect($dua['duplikat'])->toBeFalse();
    expect(HrisKetukan::query()->where('user_id', 'u-dwipa')
        ->where('tanggal_kerja', hrisDate(2026, 10, 20))->where('arah', 'PULANG')->count())->toBe(1);
    expect($dua['punch']['waktu'])->toBe(hrisMs(2026, 10, 21, 1, 0));
});

it('knows no holiday until HR enters one', function () {
    expect(app(HrisCalendar::class)->isHoliday('2026-12-25'))->toBeFalse();
});
