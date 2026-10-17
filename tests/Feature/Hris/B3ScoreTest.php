<?php

use App\Modules\Hris\Models\HrisSkor;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisScoreService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/*
 * People Score (S1–S3, S5): versioned formula, monthly snapshots with
 * completeness ranking, locked months keep their formula. September 2026
 * punches + shifts keep the components deterministic; the formula under
 * test only uses deterministic components.
 */

function hrisSkorHadir(string $uid, int $day): void
{
    hrisEnrolOnce($uid);
    hrisPlantShift($uid, hrisDate(2026, 9, $day), 'PAGI', '08:00', '17:00');
    app(HrisAttendanceService::class)->recordPunch(['id' => $uid, 'name' => $uid],
        hrisWajahPunch($uid, 'MASUK', []), hrisMs(2026, 9, $day, 7, 55));
}

it('starts on the default formula and validates new versions', function () {
    hrisSettings();
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $r = test()->getJson('/api/v1/hris/skor/formula', $h)->assertOk()->json('data');
    expect($r['aktif'])->toMatchArray(['versi' => 0]);

    test()->postJson('/api/v1/hris/skor/formula',
        ['komponen' => ['kehadiran' => 50, 'ramalan' => 50], 'berlaku_dari' => '2026-09-01'], $h)
        ->assertStatus(422);
    $v1 = test()->postJson('/api/v1/hris/skor/formula',
        ['komponen' => ['kehadiran' => 50, 'ketepatan' => 30, 'disiplin' => 20],
            'berlaku_dari' => '2026-09-01'], $h)->assertCreated()->json('data');
    expect($v1['versi'])->toBe(1);
});

it('keeps formula writes from crew', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    test()->postJson('/api/v1/hris/skor/formula',
        ['komponen' => ['kehadiran' => 100], 'berlaku_dari' => '2026-09-01'],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403);
});

it('computes a deterministic score on three components', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisMakeHr('u-admin');
    app(HrisScoreService::class)->saveFormula(
        ['kehadiran' => 50, 'ketepatan' => 30, 'disiplin' => 20], '2026-09-01', 'test');
    hrisClearMonth('u-dwipa', '2026-09');
    foreach ([7, 8, 9, 10] as $d) {
        hrisSkorHadir('u-dwipa', $d);
    }

    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->postJson('/api/v1/hris/skor/hitung', ['bulan' => '2026-09', 'user' => 'u-dwipa'], $h)
        ->assertOk()->json('data');

    // 4/4 hadir, all on time, no violations.
    expect($r[0]['skor'])->toEqual(100)->and($r[0]['kelengkapan'])->toEqual(100)
        ->and($r[0]['formula_versi'])->toBe(1);
    expect($r[0]['diranking'])->toBeTrue();
    // Order is not part of the contract (MySQL's JSON type re-sorts keys).
    expect(array_keys($r[0]['komponen']))->toEqualCanonicalizing(['kehadiran', 'ketepatan', 'disiplin']);
});

it('counts an early PULANG as not on time', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisMakeHr('u-admin');
    app(HrisScoreService::class)->saveFormula(['ketepatan' => 100], '2026-09-01', 'test');
    hrisClearMonth('u-dwipa', '2026-09');
    hrisSkorHadir('u-dwipa', 7); // MASUK 07:55, on time on arrival
    // PULANG after 4 h of a 9 h target: VALID but early.
    app(HrisAttendanceService::class)->recordPunch(['id' => 'u-dwipa', 'name' => 'u-dwipa'],
        hrisWajahPunch('u-dwipa', 'PULANG'), hrisMs(2026, 9, 7, 12, 0));

    $t = hrisToken('u-admin');
    $r = test()->postJson('/api/v1/hris/skor/hitung', ['bulan' => '2026-09', 'user' => 'u-dwipa'],
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    expect($r[0]['komponen']['ketepatan']['skor'])->toEqual(0);
});

it('keeps locked snapshots on their formula version', function () {
    hrisSettings(['tanpaShiftBoleh' => false]);
    hrisMakeHr('u-admin');
    $svc = app(HrisScoreService::class);
    $svc->saveFormula(['kehadiran' => 50, 'ketepatan' => 30, 'disiplin' => 20], '2026-09-01', 'test');
    hrisClearMonth('u-dwipa', '2026-09');
    hrisSkorHadir('u-dwipa', 7);

    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson('/api/v1/hris/skor/hitung', ['bulan' => '2026-09', 'user' => 'u-dwipa'], $h)->assertOk();
    test()->postJson('/api/v1/hris/skor/kunci', ['bulan' => '2026-09'], $h)->assertOk();

    $svc->saveFormula(['kehadiran' => 100], '2026-10-01', 'test');
    test()->postJson('/api/v1/hris/skor/hitung', ['bulan' => '2026-09', 'user' => 'u-dwipa'], $h)
        ->assertStatus(409);

    $row = HrisSkor::query()->where('user_id', 'u-dwipa')->where('bulan', '2026-09')->first();
    expect($row->formula_versi)->toBe(1)->and((bool) $row->terkunci)->toBeTrue();

    $list = test()->getJson('/api/v1/hris/skor?bulan=2026-09', $h)->assertOk()->json('data');
    expect($list['formula']['versi'])->toBe(1);
});

it('rates members as head, never self or crew', function () {
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();

    $t = hrisToken('u-arif');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->postJson('/api/v1/hris/penilaian',
        ['user' => $crew, 'bulan' => '2026-09',
            'aspek' => ['kerjasama' => 4, 'inisiatif' => 5, 'tanggungjawab' => 4]], $h)
        ->assertOk()->json('data');
    expect($r['aspek'])->toMatchArray(['kerjasama' => 4]);

    test()->postJson('/api/v1/hris/penilaian',
        ['user' => 'u-arif', 'bulan' => '2026-09', 'aspek' => ['x' => 5]], $h)->assertStatus(403);
    test()->postJson('/api/v1/hris/penilaian',
        ['user' => $crew, 'bulan' => '2026-09', 'aspek' => ['x' => 9]], $h)->assertStatus(422);
});

it('keeps crew out of ratings', function () {
    $crew = hrisBarCrew();
    if ($crew === null) {
        $this->markTestSkipped('no bar crew in the restored roster');
    }
    hrisSettings();

    $t = hrisToken('u-dwipa');
    test()->postJson('/api/v1/hris/penilaian',
        ['user' => $crew, 'bulan' => '2026-09', 'aspek' => ['x' => 5]],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403);
});
