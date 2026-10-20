<?php

use App\Modules\Akademi\Services\AkademiRecords;
use App\Modules\Akademi\Services\AkademiState;
use App\Modules\Hris\Models\HrisKetukan;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisBalanceService;
use App\Modules\Hris\Services\HrisOnboardingService;
use App\Modules\Hris\Services\HrisPerhatianService;
use App\Modules\Hris\Services\HrisRequestService;
use App\Modules\Hris\Services\HrisShiftService;
use App\Modules\Hris\Services\PunchRules;
use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/*
 * perhatian[] (PWA pivot, ADR-0010): computed on request for the token
 * holder, no push, no scheduler, no stored state. One test per kind,
 * positive and negative.
 */

/** Kode list of the perhatian box for the user at a fixed instant. */
function hrisKode(array $me, int $ms): array
{
    return array_column(app(HrisPerhatianService::class)->untuk($me, $ms), 'kode');
}

/** Finish every mandatory Akademi material, so the study check stays silent. */
function hrisSelesaikanMateri(string $uid): void
{
    $ak = app(AkademiRecords::class);
    $materi = $ak->list('materials', ['published' => '1', 'mandatory' => '1'], 1, 10000)['rows'] ?? [];
    $map = [];
    foreach ($materi as $m) {
        $m = (array) $m;
        $id = (string) ($m['id'] ?? $m['materialId'] ?? '');
        if ($id !== '') {
            $map[$id] = ['status' => 'done', 'updatedAt' => PunchRules::ms()];
        }
    }
    if ($map !== []) {
        // One batch call: per-cell progressPut would delete the other cells.
        app(AkademiState::class)->saveProgress(Modules::db('akademi'), [$uid => $map]);
    }
}

it('warns belum_masuk after the threshold, not before or after punching', function () {
    hrisSettings();
    $tgl = hrisDate(2026, 10, 20);
    hrisPlantShift('u-dwipa', $tgl, 'PAGI', '08:00', '17:00');
    $me = hrisMe('u-dwipa');

    // Default perhatianTelatMenit is 15: 08:10 is still fine.
    expect(hrisKode($me, hrisMs(2026, 10, 20, 8, 10)))->not->toContain('belum_masuk');

    $items = app(HrisPerhatianService::class)->untuk($me, hrisMs(2026, 10, 20, 8, 20));
    $kodes = array_column($items, 'kode');
    expect($kodes)->toContain('belum_masuk');
    $item = $items[array_search('belum_masuk', $kodes, true)];
    expect($item)->toMatchArray(['layar' => '/absen', 'tingkat' => 'penting']);
    expect($item)->toHaveKeys(['kode', 'judul', 'pesan', 'layar', 'tingkat']);

    // Once punched in, the warning is gone.
    app(HrisAttendanceService::class)->recordPunch($me,
        ['arah' => 'MASUK', 'alasan' => 'uji'], hrisMs(2026, 10, 20, 8, 5));
    expect(hrisKode($me, hrisMs(2026, 10, 20, 9, 0)))->not->toContain('belum_masuk');
});

it('warns belum_pulang after shift end, not before or after punching out', function () {
    hrisSettings();
    $tgl = hrisDate(2026, 10, 20);
    hrisPlantShift('u-dwipa', $tgl, 'PAGI', '08:00', '17:00');
    $me = hrisMe('u-dwipa');
    app(HrisAttendanceService::class)->recordPunch($me,
        ['arah' => 'MASUK', 'alasan' => 'uji'], hrisMs(2026, 10, 20, 8, 0));

    // Default perhatianPulangMenit is 60: 17:30 is still fine.
    expect(hrisKode($me, hrisMs(2026, 10, 20, 17, 30)))->not->toContain('belum_pulang');
    expect(hrisKode($me, hrisMs(2026, 10, 20, 18, 30)))->toContain('belum_pulang');

    app(HrisAttendanceService::class)->recordPunch($me,
        ['arah' => 'PULANG', 'alasan' => 'uji'], hrisMs(2026, 10, 20, 18, 0));
    expect(hrisKode($me, hrisMs(2026, 10, 20, 19, 0)))->not->toContain('belum_pulang');
});

/** SPLIT as two blocks: 11:00–15:00 and 18:00–23:00 (J4). */
function hrisSplitDuaBlok(): void
{
    app(HrisShiftService::class)->update('SPLIT', ['blok' => [
        ['mulai' => '11:00', 'selesai' => '15:00'], ['mulai' => '18:00', 'selesai' => '23:00']]], 'test');
}

/** A punch at a fixed WIB instant on 2026-10-20. */
function hrisPunchJam(string $uid, string $arah, int $h, int $mi): void
{
    app(HrisAttendanceService::class)->recordPunch(hrisMe($uid),
        ['arah' => $arah, 'alasan' => 'uji'], hrisMs(2026, 10, 20, $h, $mi));
}

it('warns belum_masuk for block 2 at its own start, not block 1 hours', function () {
    hrisSettings();
    hrisSplitDuaBlok();
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'SPLIT', '11:00', '23:00');
    $me = hrisMe('u-dwipa');

    // Block 1 is complete, so only block 2 can be missing.
    hrisPunchJam('u-dwipa', 'MASUK', 10, 58);
    hrisPunchJam('u-dwipa', 'PULANG', 15, 1);

    expect(hrisKode($me, hrisMs(2026, 10, 20, 18, 10)))->not->toContain('belum_masuk');
    $items = app(HrisPerhatianService::class)->untuk($me, hrisMs(2026, 10, 20, 18, 20));
    $kodes = array_column($items, 'kode');
    expect($kodes)->toContain('belum_masuk');
    $item = $items[array_search('belum_masuk', $kodes, true)];
    expect($item['judul'])->toContain('blok 2')
        ->and($item['pesan'])->toContain('18:00');

    hrisPunchJam('u-dwipa', 'MASUK', 18, 5);
    expect(hrisKode($me, hrisMs(2026, 10, 20, 18, 30)))->not->toContain('belum_masuk');
});

it('warns belum_pulang for block 1 and keeps warning after block 2 starts', function () {
    hrisSettings();
    hrisSplitDuaBlok();
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'SPLIT', '11:00', '23:00');
    $me = hrisMe('u-dwipa');

    hrisPunchJam('u-dwipa', 'MASUK', 11, 0);
    // Block 1 ends 15:00; the default 60-minute grace ends at 16:00.
    expect(hrisKode($me, hrisMs(2026, 10, 20, 15, 30)))->not->toContain('belum_pulang');
    expect(hrisKode($me, hrisMs(2026, 10, 20, 16, 20)))->toContain('belum_pulang');

    // Block 2 starts and gets its MASUK; block 1 is still open.
    hrisPunchJam('u-dwipa', 'MASUK', 18, 5);
    $items = app(HrisPerhatianService::class)->untuk($me, hrisMs(2026, 10, 20, 18, 20));
    $kodes = array_column($items, 'kode');
    expect($kodes)->toContain('belum_pulang');
    $item = $items[array_search('belum_pulang', $kodes, true)];
    expect($item['judul'])->toContain('blok 1')
        ->and($item['pesan'])->toContain('15:00');
});

it('keeps the plain shift message free of block wording', function () {
    hrisSettings();
    hrisPlantShift('u-dwipa', hrisDate(2026, 10, 20), 'PAGI', '08:00', '17:00');
    $me = hrisMe('u-dwipa');

    hrisPunchJam('u-dwipa', 'MASUK', 8, 0);
    $items = app(HrisPerhatianService::class)->untuk($me, hrisMs(2026, 10, 20, 18, 30));
    $kodes = array_column($items, 'kode');
    expect($kodes)->toContain('belum_pulang');
    $item = $items[array_search('belum_pulang', $kodes, true)];
    expect($item['judul'])->toBe('Belum absen pulang')
        ->and($item['pesan'])->toBe('Shift selesai 17:00. Absen pulang sekarang.');
});

it('warns about PH expiring within 14 days', function () {
    hrisSettings();
    $me = hrisMe('u-dwipa');
    $saldo = app(HrisBalanceService::class);
    $saldo->earn('u-dwipa', 'PH', 2, 'JATAH', 'uji-segera', '2026-10-01', '2026-10-25', 'uji');
    expect(hrisKode($me, hrisMs(2026, 10, 20, 9, 0)))->toContain('ph_hangus');
});

it('stays silent about PH expiring later', function () {
    hrisSettings();
    app(HrisBalanceService::class)
        ->earn('u-dwipa', 'PH', 2, 'JATAH', 'uji-lama', '2026-10-01', '2026-12-01', 'uji');
    expect(hrisKode(hrisMe('u-dwipa'), hrisMs(2026, 10, 20, 9, 0)))->not->toContain('ph_hangus');
});

it('shows waiting requests only to approvers', function () {
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();
    hrisMakeHr('u-admin');
    $req = app(HrisRequestService::class)->create(hrisMe($crew),
        ['jenis' => 'IZIN', 'dari' => '2026-11-02', 'alasan' => 'Sakit',
            'lampiran' => base64_encode('SURATDOKTER'), 'lampiran_nama' => 'surat.txt']);
    expect($req->status)->toBe('MENUNGGU');

    // The head sees it; the requesting crew member does not.
    expect(hrisKode(hrisMe('u-arif'), hrisMs(2026, 10, 20, 9, 0)))->toContain('pengajuan_menunggu');
    expect(hrisKode(hrisMe($crew), hrisMs(2026, 10, 20, 9, 0)))->not->toContain('pengajuan_menunggu');

    // After the head step, HR sees it instead of the head.
    app(HrisRequestService::class)->decide(hrisMe('u-arif'), $req, 'DISETUJUI', 'ok');
    expect(hrisKode(hrisMe('u-admin'), hrisMs(2026, 10, 20, 9, 0)))->toContain('pengajuan_menunggu');
    expect(hrisKode(hrisMe('u-arif'), hrisMs(2026, 10, 20, 9, 0)))->not->toContain('pengajuan_menunggu');
});

it('shows recent queue and request decisions to the requester', function () {
    hrisSettings(['tanpaShiftBoleh' => true]);
    hrisMakeHr('u-admin');
    app(HrisAttendanceService::class)->saveLocation(
        ['nama' => 'Outlet', 'lat' => -6.2, 'lng' => 106.8, 'radius' => 120, 'aktif' => true]);
    $me = hrisMe('u-dwipa');
    $now = PunchRules::ms();

    // Far from the outlet with a reason: queued, not directly valid.
    $out = app(HrisAttendanceService::class)->recordPunch($me,
        ['arah' => 'MASUK', 'lat' => -6.0, 'lng' => 107.5, 'alasan' => 'dinas luar'], $now);
    expect($out['punch']['status'])->toBe('MENUNGGU');
    $row = HrisKetukan::query()->find($out['punch']['id']);

    // Nothing decided yet: silence.
    expect(hrisKode($me, $now))->not->toContain('putusan_terbaru');

    app(HrisAttendanceService::class)
        ->decide($row->id, 'VALID', 'ok', 'Admin', (int) $row->version);
    expect(hrisKode($me, $now))->toContain('putusan_terbaru');

    // Old decisions (over 3 days ago) fall off the box.
    expect(hrisKode($me, $now + 4 * 24 * 3600 * 1000))->not->toContain('putusan_terbaru');
});

it('shows near onboarding deadlines', function () {
    hrisSettings();
    hrisMakeHr('u-admin');
    app(HrisOnboardingService::class)->mulai(hrisMe('u-admin'), 'u-dwipa', '2026-06-01', 'bar');

    $kodes = hrisKode(hrisMe('u-dwipa'), hrisMs(2026, 10, 20, 9, 0));
    expect($kodes)->toContain('tenggat_belajar');
});

it('stays silent about onboarding without deadlines', function () {
    hrisSettings();
    hrisSelesaikanMateri('u-dwipa');
    expect(hrisKode(hrisMe('u-dwipa'), hrisMs(2026, 10, 20, 9, 0)))->not->toContain('tenggat_belajar');
});

it('serves perhatian in GET me/today', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');

    test()->getJson('/api/v1/hris/me/today', ['Authorization' => 'Bearer '.$t])
        ->assertOk()->assertJsonPath('data.lingkup', 'sendiri');
    $r = test()->getJson('/api/v1/hris/me/today', ['Authorization' => 'Bearer '.$t])
        ->assertOk()->json('data');
    expect($r)->toHaveKey('perhatian');
    expect($r['perhatian'])->toBeArray();
});
