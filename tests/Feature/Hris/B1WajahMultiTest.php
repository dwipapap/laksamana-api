<?php

use App\Modules\Hris\Models\HrisAudit;
use App\Modules\Hris\Models\HrisWajah;
use App\Modules\Hris\Services\FaceMatcher;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisError;
use App\Modules\Hris\Services\HrisSettingsService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

/*
 * Wajah multi-descriptor (issue #295, W1–W8): FaceMatcher matches the
 * smallest distance over the stored set; a flat legacy array reads as one
 * descriptor; the built-in fallback threshold is 0.637 (OpenCV SFace
 * similarity >= 0.363).
 */

it('matches a flat legacy descriptor as one descriptor', function () {
    $stored = hrisWajahSeed('u-dwipa');
    $ambang = ['metric' => 'cosine', 'ambang' => 0.637];

    expect(FaceMatcher::match($stored, hrisWajahSeed('u-dwipa'), $ambang)['ok'])->toBeTrue()
        ->and(FaceMatcher::match($stored, hrisWajahSeed('u-andry'), $ambang)['ok'])->toBeFalse();
});

it('matches the smallest distance over a descriptor set', function () {
    $dekat = array_map(fn ($v) => $v + 0.001, hrisWajahSeed('u-dwipa'));
    $stored = [hrisWajahSeed('u-andry'), hrisWajahSeed('u-dwipa')];
    $ambang = ['metric' => 'cosine', 'ambang' => 0.637];

    $m = FaceMatcher::match($stored, $dekat, $ambang);
    expect($m['ok'])->toBeTrue()->and($m['terdaftar'])->toBeTrue();

    $jauh = FaceMatcher::match($stored, hrisWajahSeed('u-admin'), $ambang);
    expect($jauh['ok'])->toBeFalse()->and($jauh['terdaftar'])->toBeTrue();
});

it('falls back to the 0.637 threshold when nothing is stored', function () {
    expect(FaceMatcher::ambangUntuk('model-baru', []))->toBe(['metric' => 'cosine', 'ambang' => 0.637]);
});

it('defaults the stored threshold to 0.637 per model', function () {
    hrisSettings();
    $peta = app(HrisSettingsService::class)->all()['wajahAmbang'];

    expect($peta['default'])->toBe(['metric' => 'cosine', 'ambang' => 0.637])
        ->and($peta['sface-2021dec-int8'])->toBe(['metric' => 'cosine', 'ambang' => 0.637]);
});

it('shares one rate limit between cek and tambah', function () {
    hrisSettings();
    Storage::fake('local');
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    $body = ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8'];

    for ($i = 0; $i < 9; $i++) {
        test()->postJson('/api/v1/hris/faces/me/cek', $body, $h)->assertOk();
    }
    // The top-up is the 10th hit on the shared counter (same face, so it
    // passes the match and appends a second descriptor).
    test()->postJson('/api/v1/hris/faces/me/tambah', $body, $h)->assertOk();

    // The 11th hit — back on cek — is over the shared limit.
    test()->postJson('/api/v1/hris/faces/me/cek', $body, $h)
        ->assertStatus(429)->assertJsonPath('error.code', 'terlalu_sering');
});

it('enrols with three descriptors at once', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $mirip = array_map(fn ($v) => $v + 0.001, hrisWajahSeed('u-dwipa'));
    $mirip2 = array_map(fn ($v) => $v - 0.001, hrisWajahSeed('u-dwipa'));

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptors' => [hrisWajahSeed('u-dwipa'), $mirip, $mirip2],
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()],
        ['Authorization' => 'Bearer '.$t])->assertCreated();

    $row = HrisWajah::query()->where('user_id', 'u-dwipa')->where('status', 'AKTIF')->first();
    expect($row)->not->toBeNull()
        ->and(FaceMatcher::storedSet($row->descriptor))->toHaveCount(3);
});

it('still accepts the old single descriptor on enrol', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisWajahSeed('u-dwipa'),
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()],
        ['Authorization' => 'Bearer '.$t])->assertCreated();

    expect(app(HrisAttendanceService::class)->faceEnrolled('u-dwipa'))->toBeTrue();
});

it('rejects a spread-out set from one enrolment', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');

    // Two descriptors of different people in one enrolment.
    test()->postJson('/api/v1/hris/faces/me',
        ['descriptors' => [hrisWajahSeed('u-dwipa'), hrisWajahSeed('u-andry')],
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect(app(HrisAttendanceService::class)->faceEnrolled('u-dwipa'))->toBeFalse();
});

it('rejects a duplicate against another person second descriptor', function () {
    hrisSettings();
    Storage::fake('local');
    $kedua = array_map(fn ($v) => $v + 0.001, hrisWajahSeed('u-dwipa'));
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptors' => [hrisWajahSeed('u-dwipa'), $kedua],
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);

    // u-andry copies u-dwipa's SECOND descriptor (single legacy field, so
    // the within-set check passes trivially; the duplicate check must
    // still catch it through the minimum over the stored set).
    try {
        app(HrisAttendanceService::class)->enrol(hrisMe('u-andry'),
            ['descriptor' => $kedua,
                'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
        expect(false)->toBeTrue('expected wajah_milik_orang_lain');
    } catch (HrisError $e) {
        expect($e->errorCode)->toBe('wajah_milik_orang_lain')->and($e->status)->toBe(409);
    }
    expect(HrisWajah::query()->where('user_id', 'u-andry')->where('status', 'AKTIF')->exists())->toBeFalse();
});

it('completes your own face up to three descriptors', function () {
    hrisSettings();
    Storage::fake('local');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisWajahSeed('u-dwipa'),
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()], $h)->assertCreated();

    $mirip = array_map(fn ($v) => $v + 0.001, hrisWajahSeed('u-dwipa'));
    $r = test()->postJson('/api/v1/hris/faces/me/tambah',
        ['descriptor' => $mirip, 'model' => 'sface-2021dec-int8'], $h)
        ->assertOk()->json('data');
    expect($r)->toMatchArray(['user_id' => 'u-dwipa', 'jumlah_descriptor' => 2]);

    expect(HrisAudit::query()->where('aksi', 'wajah_dilengkapi')->where('ref_id', 'u-dwipa')->exists())->toBeTrue();
});

it('refuses a top-up that does not match the stored face', function () {
    hrisSettings();
    Storage::fake('local');
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisWajahSeed('u-dwipa'),
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me/tambah',
        ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8'],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(422)->assertJsonPath('error.code', 'wajah_tidak_cocok');
});

it('refuses a top-up at three descriptors', function () {
    hrisSettings();
    Storage::fake('local');
    $mirip = array_map(fn ($v) => $v + 0.001, hrisWajahSeed('u-dwipa'));
    $mirip2 = array_map(fn ($v) => $v - 0.001, hrisWajahSeed('u-dwipa'));
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptors' => [hrisWajahSeed('u-dwipa'), $mirip, $mirip2],
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me/tambah',
        ['descriptor' => $mirip, 'model' => 'sface-2021dec-int8'],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(409)->assertJsonPath('error.code', 'wajah_lengkap');
});

it('refuses a top-up matching someone else', function () {
    hrisSettings();
    Storage::fake('local');
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisWajahSeed('u-dwipa'),
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    app(HrisAttendanceService::class)->enrol(hrisMe('u-andry'),
        ['descriptor' => hrisWajahSeed('u-andry'),
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    $t = hrisToken('u-andry');

    // u-andry tops up with u-dwipa's face: it matches the stored set? No —
    // it must match their OWN set first, so use the midpoint: close enough
    // to their own face and to u-dwipa's.
    $tengah = array_map(fn ($a, $b) => ($a + $b) / 2, hrisWajahSeed('u-andry'), hrisWajahSeed('u-dwipa'));
    test()->postJson('/api/v1/hris/faces/me/tambah',
        ['descriptor' => $tengah, 'model' => 'sface-2021dec-int8'],
        ['Authorization' => 'Bearer '.$t])
        ->assertStatus(409)->assertJsonPath('error.code', 'wajah_milik_orang_lain');
    expect(HrisAudit::query()->where('aksi', 'wajah_duplikat_ditolak')->exists())->toBeTrue();
});

it('refuses a top-up of another model', function () {
    hrisSettings();
    Storage::fake('local');
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisWajahSeed('u-dwipa'),
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me/tambah',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'model-lain'],
        ['Authorization' => 'Bearer '.$t])->assertStatus(422);
});

it('audits every face check without photo or descriptor', function () {
    hrisSettings();
    Storage::fake('local');
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8'], $h)->assertOk();
    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8'], $h)->assertOk();

    $rows = HrisAudit::query()->where('aksi', 'wajah_dicek')->where('ref_id', 'u-dwipa')
        ->orderBy('waktu')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->data)->toMatchArray(['cocok' => true, 'model' => 'sface-2021dec-int8']);
    expect($rows[1]->data)->toMatchArray(['cocok' => false, 'model' => 'sface-2021dec-int8']);
    foreach ($rows as $row) {
        expect($row->data)->toHaveKeys(['skor', 'cocok', 'model'])
            ->and($row->data)->not->toHaveKey('descriptor')
            ->and($row->data)->not->toHaveKey('foto');
        expect((float) $row->data['skor'])->toBeGreaterThanOrEqual(0.0);
    }
    expect($rows[0]->data['skor'])->toBeLessThan(0.637);
    expect($rows[1]->data['skor'])->toBeGreaterThan(0.637);
});

it('does not audit a rate-limited check', function () {
    hrisSettings();
    Storage::fake('local');
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    $body = ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8'];

    for ($i = 0; $i < 10; $i++) {
        test()->postJson('/api/v1/hris/faces/me/cek', $body, $h)->assertOk();
    }
    test()->postJson('/api/v1/hris/faces/me/cek', $body, $h)->assertStatus(429);

    expect(HrisAudit::query()->where('aksi', 'wajah_dicek')->where('ref_id', 'u-dwipa')->count())->toBe(10);
});

it('lists descriptor counts and 7-day check failures for HR', function () {
    hrisSettings();
    Storage::fake('local');
    hrisMakeHr('u-admin');
    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisWajahSeed('u-dwipa'),
            'model' => 'sface-2021dec-int8', 'consent' => true, 'foto' => hrisFoto()]);
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $rows = test()->getJson('/api/v1/hris/faces', $h)->assertOk()->json('data');
    $baris = collect($rows)->firstWhere('user_id', 'u-dwipa');
    expect($baris)->toMatchArray(['jumlah_descriptor' => 1, 'gagal_cek_7h' => 0]);

    // Two failed checks through the service (one HTTP identity per test).
    $svc = app(HrisAttendanceService::class);
    $salah = ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8'];
    $svc->cekWajah(hrisMe('u-dwipa'), $salah);
    $svc->cekWajah(hrisMe('u-dwipa'), $salah);
    $svc->cekWajah(hrisMe('u-dwipa'), ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8']);

    $rows = test()->getJson('/api/v1/hris/faces', $h)->assertOk()->json('data');
    expect(collect($rows)->firstWhere('user_id', 'u-dwipa'))->toMatchArray(['gagal_cek_7h' => 2]);
});

it('serves the recent check log per person for HR', function () {
    hrisSettings();
    Storage::fake('local');
    hrisMakeHr('u-admin');
    hrisEnrolOnce('u-dwipa');
    $svc = app(HrisAttendanceService::class);
    $svc->cekWajah(hrisMe('u-dwipa'), ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8']);
    $svc->cekWajah(hrisMe('u-dwipa'), ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8']);
    $t = hrisToken('u-admin');

    $log = test()->getJson('/api/v1/hris/faces/u-dwipa/cek?limit=10',
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    expect($log)->toHaveCount(2);
    expect($log[0]['waktu'])->toBeGreaterThanOrEqual($log[1]['waktu']);
    foreach ($log as $baris) {
        expect($baris)->toHaveKeys(['waktu', 'skor', 'cocok', 'model']);
    }
    expect([$log[0]['cocok'], $log[1]['cocok']])->toContain(true)->toContain(false);
});

it('keeps the check log from crew', function () {
    hrisSettings();
    Storage::fake('local');
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');

    // One HTTP identity per test: crew only, so HR reads are impossible.
    test()->getJson('/api/v1/hris/faces/u-andry/cek',
        ['Authorization' => 'Bearer '.$t])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('serves score percentiles per model for HR', function () {
    hrisSettings();
    Storage::fake('local');
    hrisMakeHr('u-admin');
    hrisEnrolOnce('u-dwipa');
    $svc = app(HrisAttendanceService::class);
    for ($i = 0; $i < 4; $i++) {
        $svc->cekWajah(hrisMe('u-dwipa'), ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8']);
    }
    $svc->cekWajah(hrisMe('u-dwipa'), ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8']);
    $t = hrisToken('u-admin');

    $kal = test()->getJson('/api/v1/hris/faces/kalibrasi',
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');
    $model = $kal['sface-2021dec-int8'];
    expect($model['n'])->toBe(5);
    expect($model)->toHaveKeys(['n', 'p50', 'p90', 'p95', 'p99']);
    expect($model['p50'])->toBeLessThanOrEqual($model['p90']);
    expect($model['p90'])->toBeLessThanOrEqual($model['p95']);
    expect($model['p95'])->toBeLessThanOrEqual($model['p99']);
    expect((float) $model['p50'])->toBe(0.0);
});

it('keeps the calibration from crew', function () {
    hrisSettings();
    Storage::fake('local');
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');

    test()->getJson('/api/v1/hris/faces/kalibrasi',
        ['Authorization' => 'Bearer '.$t])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('serves the face count in me/today', function () {
    hrisSettings();
    Storage::fake('local');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    $sebelum = test()->getJson('/api/v1/hris/me/today', $h)->assertOk()->json('data');
    expect($sebelum['wajahJumlah'])->toBe(0);

    hrisEnrolOnce('u-dwipa');
    $sesudah = test()->getJson('/api/v1/hris/me/today', $h)->assertOk()->json('data');
    expect($sesudah['wajahJumlah'])->toBe(1);

    $mirip = array_map(fn ($v) => $v + 0.001, hrisWajahSeed('u-dwipa'));
    app(HrisAttendanceService::class)->tambahWajah(hrisMe('u-dwipa'),
        ['descriptor' => $mirip, 'model' => 'sface-2021dec-int8']);
    $lengkap = test()->getJson('/api/v1/hris/me/today', $h)->assertOk()->json('data');
    expect($lengkap['wajahJumlah'])->toBe(2);
});
