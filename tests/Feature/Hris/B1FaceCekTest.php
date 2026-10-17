<?php

use App\Modules\Hris\Models\HrisAudit;
use App\Modules\Hris\Models\HrisWajah;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Storage::fake('local'));

/*
 * Pre-send face check (A19): POST /hris/faces/me/cek answers {terdaftar,
 * cocok} with no score, no write and no audit row, so a mismatch only warns
 * on the punch screen. Rate-limited per user: 10 per 5 minutes. One HTTP
 * identity per test.
 */

it('checks your own face before sending', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8',
            'consent' => true, 'foto' => hrisFoto()], $h)->assertCreated();

    $r = test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8'], $h)
        ->assertOk()->json('data');
    expect($r)->toBe(['terdaftar' => true, 'cocok' => true]);

    $salah = test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8'], $h)
        ->assertOk()->json('data');
    expect($salah)->toBe(['terdaftar' => true, 'cocok' => false]);
});

it('answers unregistered without a match', function () {
    hrisSettings();
    $t = hrisToken('u-andry');

    $r = test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8'],
        ['Authorization' => 'Bearer '.$t])->assertOk()->json('data');

    expect($r)->toBe(['terdaftar' => false, 'cocok' => false]);
});

it('refuses another model without matching', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    test()->postJson('/api/v1/hris/faces/me',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8',
            'consent' => true, 'foto' => hrisFoto()], $h)->assertCreated();

    // Same numbers, another model: proves nothing either way.
    $r = test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'model-lain'], $h)
        ->assertOk()->json('data');

    expect($r)->toBe(['terdaftar' => true, 'cocok' => false]);
});

it('validates like enrolment', function () {
    hrisSettings();
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => [0.5, 0.5], 'model' => 'sface-2021dec-int8'], $h)->assertStatus(422);
    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa')], $h)->assertStatus(422);
});

it('rate-limits to 10 checks per 5 minutes', function () {
    hrisSettings();
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    $body = ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8'];

    for ($i = 0; $i < 10; $i++) {
        test()->postJson('/api/v1/hris/faces/me/cek', $body, $h)->assertOk();
    }
    test()->postJson('/api/v1/hris/faces/me/cek', $body, $h)
        ->assertStatus(429)->assertJsonPath('error.code', 'terlalu_sering');
});

it('writes and audits nothing', function () {
    hrisSettings();
    hrisEnrolOnce('u-dwipa');
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    $wajah = HrisWajah::query()->count();
    $audit = HrisAudit::query()->count();

    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-dwipa'), 'model' => 'sface-2021dec-int8'], $h)->assertOk();
    test()->postJson('/api/v1/hris/faces/me/cek',
        ['descriptor' => hrisWajahSeed('u-andry'), 'model' => 'sface-2021dec-int8'], $h)->assertOk();

    expect(HrisWajah::query()->count())->toBe($wajah)
        ->and(HrisAudit::query()->count())->toBe($audit);
});
