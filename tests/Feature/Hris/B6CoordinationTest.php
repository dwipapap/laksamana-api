<?php

use App\Auth\OfficeAccess;
use App\Modules\Hris\Services\HrisCoordinationService;
use App\Support\Divisi;

require_once __DIR__.'/helpers.php';

/*
 * Inter-division tickets (R6): HRIS screens, BD stores. Every write goes
 * through BdRecords with its version guard; HRIS adds origin/division
 * scoping, target-head assignment and forward-only status.
 */

it('opens a ticket from your own division', function () {
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    $r = test()->postJson('/api/v1/hris/coordination',
        ['judul' => 'Minta backdrop', 'keDivisi' => 'bar', 'prioritas' => 'high', 'detail' => 'Ukuran 3x2'],
        $h)->assertCreated()->json('data');

    expect($r)->toMatchArray(['judul' => 'Minta backdrop', 'keDivisi' => 'bar', 'status' => 'Diminta']);
    expect($r['dariDivisi'])->not->toBe('');
    expect($r['berubah'])->toBeGreaterThan(0);

    $satu = test()->getJson("/api/v1/hris/coordination/{$r['id']}", $h)->assertOk()->json('data');
    expect($satu['id'])->toBe($r['id']);
});

it('scopes tickets to involved divisions', function () {
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];
    $r = test()->postJson('/api/v1/hris/coordination',
        ['judul' => 'Minta es', 'keDivisi' => 'bar'], $h)->assertCreated()->json('data');

    // The target head sees it; filtering works.
    $t2 = hrisToken('u-arif');
    $h2 = ['Authorization' => 'Bearer '.$t2];
    $list = test()->getJson('/api/v1/hris/coordination?divisi=bar', $h2)->assertOk()->json('data');
    expect(array_column($list, 'id'))->toContain($r['id']);
    $diminta = test()->getJson('/api/v1/hris/coordination?status=Diminta', $h2)->assertOk()->json('data');
    expect(array_column($diminta, 'id'))->toContain($r['id']);
});

/** A ticket to bar opened by u-dwipa, through the service (no HTTP identity used). */
function hrisTiketKeBar(): string
{
    return app(HrisCoordinationService::class)->buat(hrisMe('u-dwipa'),
        ['judul' => 'Minta piring', 'keDivisi' => 'bar'])['id'];
}

it('keeps crew from assigning a ticket', function () {
    $id = hrisTiketKeBar();
    $viewer = app(OfficeAccess::class)->isSuperadmin('u-andry') ? 'u-yuzaalfarel' : 'u-andry';
    if (app(Divisi::class)->isHead($viewer)) {
        $viewer = 'u-yuzaalfarel';
    }

    $tv = hrisToken($viewer);
    test()->patchJson("/api/v1/hris/coordination/{$id}",
        ['pelaksana' => 'u-dwipa', 'berubah' => 1], ['Authorization' => 'Bearer '.$tv])->assertStatus(403);
});

it('assigns and advances with the BD version guard', function () {
    $id = hrisTiketKeBar();

    // The target head assigns + advances forward.
    $t2 = hrisToken('u-arif');
    $h2 = ['Authorization' => 'Bearer '.$t2];
    $cur = test()->getJson("/api/v1/hris/coordination/{$id}", $h2)->assertOk()->json('data');
    $r = test()->patchJson("/api/v1/hris/coordination/{$id}",
        ['pelaksana' => 'u-dwipa', 'status' => 'Diproses', 'berubah' => $cur['berubah']], $h2)
        ->assertOk()->json('data');
    expect($r)->toMatchArray(['pelaksana' => 'u-dwipa', 'status' => 'Diproses']);

    // Backward is refused; stale versions conflict with the live record.
    test()->patchJson("/api/v1/hris/coordination/{$id}",
        ['status' => 'Diminta', 'berubah' => $r['berubah']], $h2)->assertStatus(422);
    test()->patchJson("/api/v1/hris/coordination/{$id}",
        ['status' => 'Review', 'berubah' => $cur['berubah']], $h2)
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');
});

it('shows Marketing design requests read-only', function () {
    $t = hrisToken('u-dwipa');
    $h = ['Authorization' => 'Bearer '.$t];

    $r = test()->getJson('/api/v1/hris/design-requests', $h)->assertOk()->json('data');
    expect($r)->toBeArray();
});
