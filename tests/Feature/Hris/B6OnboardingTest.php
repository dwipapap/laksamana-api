<?php

use App\Modules\Akademi\Services\AkademiRecords;
use App\Modules\Hris\Models\HrisOnboarding;
use App\Modules\Hris\Models\HrisPushToken;
use App\Modules\Hris\Services\ExpoPushService;
use App\Modules\Hris\Services\HrisOnboardingService;
use App\Modules\Hris\Services\HrisReminderService;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

/*
 * Onboarding (O1): HR templates and starts, the newcomer ticks their own
 * non-review steps, reviews take HR or the head. Learning stays in
 * Akademi (linked, never copied); overdue steps join the daily reminders.
 */

it('manages templates as HR', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    $id = test()->postJson('/api/v1/hris/onboarding/templates',
        ['divisi' => 'bar', 'langkah' => [
            ['kunci' => 'kontrak', 'judul' => 'Tanda tangan kontrak'],
            ['kunci' => 'eval-30', 'judul' => 'Review 30 hari', 'tenggat_hari' => 30],
        ]], $h)->assertCreated()->json('data.id');
    expect($id)->not->toBe('');

    $list = test()->getJson('/api/v1/hris/onboarding/templates', $h)->assertOk()->json('data');
    expect(array_column($list, 'divisi'))->toContain('bar');

    test()->postJson('/api/v1/hris/onboarding/templates',
        ['divisi' => 'bar', 'langkah' => [['kunci' => 'x', 'judul' => 'X'], ['kunci' => 'x', 'judul' => 'Y']]], $h)
        ->assertStatus(422);
});

it('keeps template writes from crew', function () {
    $t = hrisToken('u-dwipa');
    test()->postJson('/api/v1/hris/onboarding/templates', ['divisi' => 'x', 'langkah' => [['kunci' => 'x', 'judul' => 'X']]],
        ['Authorization' => 'Bearer '.$t])->assertStatus(403);
});

it('starts onboarding with fallback steps and deadlines', function () {
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');

    $r = test()->postJson('/api/v1/hris/onboarding/start',
        ['user' => 'u-dwipa', 'mulai' => '2026-09-01', 'divisi' => 'bar'],
        ['Authorization' => 'Bearer '.$t])->assertCreated()->json('data');

    expect($r)->toMatchArray(['user_id' => 'u-dwipa', 'status' => 'AKTIF']);
    $kunci = array_column($r['langkah'], 'kunci');
    expect($kunci)->toContain('kontrak', 'eval-30', 'eval-90');
    foreach ($r['langkah'] as $l) {
        if ($l['kunci'] === 'eval-30') {
            expect($l['tenggat'])->toBe('2026-10-01');
        }
    }
    expect($r['maju'])->toBe([0, 7]);

    test()->postJson('/api/v1/hris/onboarding/start',
        ['user' => 'u-dwipa', 'mulai' => '2026-09-01'],
        ['Authorization' => 'Bearer '.$t])->assertStatus(409);
});

it('ticks own steps but never own reviews', function () {
    hrisMakeHr('u-admin');
    app(HrisOnboardingService::class)->mulai(hrisMe('u-admin'), 'u-dwipa', '2026-09-01', 'bar');
    $ob = HrisOnboarding::query()->where('user_id', 'u-dwipa')->first();

    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->postJson("/api/v1/hris/onboarding/{$ob->id}/langkah/kontrak",
        ['selesai' => true, 'catatan' => 'ok'], $h)->assertOk()->json('data');
    expect($r)->toMatchArray(['kunci' => 'kontrak', 'selesai' => true]);

    test()->postJson("/api/v1/hris/onboarding/{$ob->id}/langkah/eval-30",
        ['selesai' => true], $h)->assertStatus(403);
});

it('lets HR complete reviews', function () {
    hrisMakeHr('u-admin');
    app(HrisOnboardingService::class)->mulai(hrisMe('u-admin'), 'u-dwipa', '2026-09-01', 'bar');
    $ob = HrisOnboarding::query()->where('user_id', 'u-dwipa')->first();

    $t = hrisToken('u-admin');
    test()->postJson("/api/v1/hris/onboarding/{$ob->id}/langkah/eval-30",
        ['selesai' => true, 'catatan' => 'bagus'],
        ['Authorization' => 'Bearer '.$t])->assertOk()->assertJsonPath('data.selesai', true);
});

it('reminds overdue onboarding steps', function () {
    Http::fake([
        ExpoPushService::TIKET_URL => Http::response(['data' => [['status' => 'ok', 'id' => 't1']]], 200),
        ExpoPushService::TANDA_URL => Http::response(['data' => ['t1' => ['status' => 'ok']]], 200),
    ]);
    hrisSettings();
    HrisPushToken::query()->create(['user_id' => 'u-dwipa',
        'token' => 'ExponentPushToken[dwipa]', 'platform' => 'android']);
    app(HrisOnboardingService::class)->mulai(hrisMe('u-admin'), 'u-dwipa', '2026-09-01', 'bar');

    $r = app(HrisReminderService::class)->onboardingJatuhTempo('2026-10-15');
    expect($r['diingatkan'])->toBeGreaterThanOrEqual(1);
    expect(app(HrisReminderService::class)->onboardingJatuhTempo('2026-10-15')['diingatkan'])->toBe(0);
});

it('links the division Akademi program when one exists', function () {
    try {
        $rows = app(AkademiRecords::class)->list('programs', [], 1, 1)['rows'] ?? [];
    } catch (Throwable) {
        $rows = [];
    }
    if ($rows === []) {
        $this->markTestSkipped('no Akademi programs in this environment');
    }
    hrisMakeHr('u-admin');
    $t = hrisToken('u-admin');
    $h = ['Authorization' => 'Bearer '.$t];

    test()->postJson('/api/v1/hris/onboarding/templates',
        ['divisi' => 'bar', 'program_akademi_id' => 'tidak-ada',
            'langkah' => [['kunci' => 'kontrak', 'judul' => 'Kontrak']]], $h)->assertStatus(422);

    $pid = (string) ($rows[0]['id'] ?? '');
    $r = test()->postJson('/api/v1/hris/onboarding/start',
        ['user' => 'u-dwipa', 'mulai' => '2026-09-01', 'divisi' => 'bar'],
        $h)->assertCreated()->json('data');
    expect($r['program'])->toBeNull();

    test()->postJson('/api/v1/hris/onboarding/templates',
        ['divisi' => 'floor', 'program_akademi_id' => $pid,
            'langkah' => [['kunci' => 'kontrak', 'judul' => 'Kontrak']]], $h)->assertCreated();
    $r = test()->postJson('/api/v1/hris/onboarding/start',
        ['user' => 'u-andry', 'mulai' => '2026-09-01', 'divisi' => 'floor'],
        $h)->assertCreated()->json('data');
    expect($r['program']['id'])->toBe($pid);
});
