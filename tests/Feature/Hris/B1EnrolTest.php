<?php

use App\Modules\Hris\Models\HrisAudit;
use App\Modules\Hris\Models\HrisWajah;
use App\Modules\Hris\Services\HrisAttendanceService;
use App\Modules\Hris\Services\HrisError;
use Illuminate\Support\Facades\Storage;

/*
 * Face enrolment (A3): self-service, active at once, consent required; a face
 * matching someone else's is rejected and reported to HR; re-enrolment only
 * after an HR reset. One HTTP identity per test; the second person enrols
 * through the service directly.
 */

it('enrols your own face, active at once', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');

    $r = test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisDescriptor(), 'model' => 'mobilefacenet-v1', 'consent' => true],
        ['Authorization' => 'Bearer '.$t])->assertCreated()->json();

    expect($r['data'])->toMatchArray(['user_id' => 'u-dwipa', 'status' => 'AKTIF']);
    expect(HrisWajah::query()->where('user_id', 'u-dwipa')->where('status', 'AKTIF')->exists())->toBeTrue();
});

it('refuses enrolment without consent', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisDescriptor(), 'model' => 'mobilefacenet-v1'],
        ['Authorization' => 'Bearer '.$t])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a second enrolment while AKTIF', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisDescriptor(), 'model' => 'mobilefacenet-v1', 'consent' => true], $h)->assertCreated();

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisDescriptor(-0.5), 'model' => 'mobilefacenet-v1', 'consent' => true], $h)
        ->assertStatus(409)->assertJsonPath('error.code', 'already_enrolled');
});

it('rejects a face matching someone else and audits it', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisDescriptor(), 'model' => 'mobilefacenet-v1', 'consent' => true],
        ['Authorization' => 'Bearer '.$t])->assertCreated();

    // The second person enrols through the service (one HTTP identity per test).
    try {
        app(HrisAttendanceService::class)->enrol(hrisMe('u-andry'),
            ['descriptor' => hrisDescriptor(), 'model' => 'mobilefacenet-v1', 'consent' => true, 'foto' => null]);
        expect(false)->toBeTrue('expected wajah_milik_orang_lain');
    } catch (HrisError $e) {
        expect($e->errorCode)->toBe('wajah_milik_orang_lain')->and($e->status)->toBe(409);
    }
    expect(HrisWajah::query()->where('user_id', 'u-andry')->where('status', 'AKTIF')->exists())->toBeFalse();
    expect(HrisAudit::query()->where('aksi', 'wajah_duplikat_ditolak')->exists())->toBeTrue();

    // A genuinely different face enrols fine.
    $row = app(HrisAttendanceService::class)->enrol(hrisMe('u-andry'),
        ['descriptor' => hrisDescriptor(-0.5), 'model' => 'mobilefacenet-v1', 'consent' => true, 'foto' => null]);
    expect($row->status)->toBe('AKTIF');
});

it('lets HR reset a face so the person may enrol again', function () {
    hrisSettings();
    hrisMakeHr('u-admin');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisDescriptor(), 'model' => 'mobilefacenet-v1', 'consent' => true], $h)->assertCreated();

    // Crew cannot reset (HR only) — through the service would bypass auth, so
    // the reset below runs as the HR user.
    expect(app(HrisAttendanceService::class)->resetFace('Admin', 'u-dwipa'))->toBeTrue();
    expect(HrisWajah::query()->where('user_id', 'u-dwipa')->where('status', 'AKTIF')->exists())->toBeFalse();

    // Re-enrolment works after the reset.
    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisDescriptor(-0.5), 'model' => 'mobilefacenet-v1', 'consent' => true], $h)->assertCreated();
});

it('resets faces over HTTP as HR, 404 when none', function () {
    hrisSettings();
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->deleteJson('/api/v1/hris/faces/u-dwipa', [], $h)->assertStatus(404);

    app(HrisAttendanceService::class)->enrol(hrisMe('u-dwipa'),
        ['descriptor' => hrisDescriptor(), 'model' => 'mobilefacenet-v1', 'consent' => true, 'foto' => null]);
    test()->deleteJson('/api/v1/hris/faces/u-dwipa', [], $h)->assertOk()->assertJsonPath('data.direset', true);
});

it('hides descriptors from the HR face list but streams the photo', function () {
    Storage::fake('local');
    hrisSettings();
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisDescriptor(), 'model' => 'mobilefacenet-v1',
            'foto' => base64_encode('FOTOUJI'), 'consent' => true], $h)->assertCreated();

    $r = test()->getJson('/api/v1/hris/faces', $h)->assertOk()->json('data');
    expect($r)->not->toBe([]);
    foreach ($r as $row) {
        expect($row)->not->toHaveKey('descriptor');
    }

    test()->get('/api/v1/hris/faces/u-admin/photo', $h)->assertOk();
    test()->getJson('/api/v1/hris/faces/u-tidak-ada/photo', $h)->assertStatus(404);
});

it('keeps the face list and photos from crew', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->getJson('/api/v1/hris/faces', $h)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    test()->getJson('/api/v1/hris/faces/u-dwipa/photo', $h)->assertStatus(403);
    test()->deleteJson('/api/v1/hris/faces/u-dwipa', [], $h)->assertStatus(403);
});
