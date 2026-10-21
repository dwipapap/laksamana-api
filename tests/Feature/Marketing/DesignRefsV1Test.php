<?php

require_once __DIR__.'/helpers.php';

/*
 * #341: GET /state sends design-request photos as stubs, and a save that sends
 * those stubs back must keep the stored photos. u-aurel holds `marketing`.
 */

beforeEach(function () {
    config(['laksamana.modules.marketing.data_dir' => storage_path('framework/testing/marketing-db')]);
});

const DR_FOTO_A = 'data:image/png;base64,QUFBQUFB';
const DR_FOTO_B = 'data:image/jpeg;base64,QkJCQkJC';
const DR_FOTO_C = 'data:image/png;base64,Q0NDQ0ND';

/** A request with two photos and one link; returns [id, version]. */
function drBuat(string $token): array
{
    $res = test()->withToken($token)->postJson('/api/v1/marketing/design-requests', [
        'judul' => 'Desain Foto', 'jenis' => 'design',
        'refs' => [
            ['t' => 'img', 'v' => DR_FOTO_A, 'n' => 'a.png'],
            ['t' => 'link', 'v' => 'https://contoh.id/x', 'n' => 'contoh'],
            ['t' => 'img', 'v' => DR_FOTO_B, 'n' => 'b.jpg'],
        ],
    ])->assertCreated();

    return [$res->json('data.id'), $res->json('meta.version')];
}

function drDariState(string $token, string $id): array
{
    $rows = test()->withToken($token)->getJson('/api/v1/marketing/state')->assertOk()->json('data.designreqs');
    foreach ($rows as $r) {
        if (($r['id'] ?? '') === $id) {
            return $r;
        }
    }
    throw new RuntimeException("request $id not in /state");
}

function drRefs(string $token, string $id): array
{
    return test()->withToken($token)->getJson("/api/v1/marketing/design-requests/$id")->assertOk()->json('data.req.refs');
}

it('sends photos as stubs in /state and keeps links whole', function () {
    $token = loginAs(officeUser('u-aurel'));
    [$id] = drBuat($token);

    $refs = drDariState($token, $id)['refs'];
    expect($refs)->toHaveCount(3)
        ->and($refs[0])->toBe(['t' => 'img', 'n' => 'a.png', 'dipangkas' => true])
        ->and($refs[1]['v'])->toBe('https://contoh.id/x')
        ->and($refs[2])->toBe(['t' => 'img', 'n' => 'b.jpg', 'dipangkas' => true]);

    // The photos are still there, one request at a time.
    $full = drRefs($token, $id);
    expect($full[0]['v'])->toBe(DR_FOTO_A)->and($full[2]['v'])->toBe(DR_FOTO_B);
});

it('a save that sends the stubs back keeps the stored photos', function () {
    $token = loginAs(officeUser('u-aurel'));
    [$id, $ver] = drBuat($token);
    $row = drDariState($token, $id);
    $row['judul'] = 'Desain Foto (revisi)';

    $this->withToken($token)->withHeader('If-Match', '"'.$ver.'"')
        ->patchJson("/api/v1/marketing/design-requests/$id", $row)->assertOk();

    $full = drRefs($token, $id);
    expect($full)->toHaveCount(3)
        ->and($full[0])->toBe(['t' => 'img', 'v' => DR_FOTO_A, 'n' => 'a.png'])
        ->and($full[2])->toBe(['t' => 'img', 'v' => DR_FOTO_B, 'n' => 'b.jpg']);
});

it('keeps stubs, adds a new photo and drops the one left out', function () {
    $token = loginAs(officeUser('u-aurel'));
    [$id, $ver] = drBuat($token);
    $refs = drDariState($token, $id)['refs'];

    // Removed photo A on purpose, kept B as a stub, added C in full.
    $this->withToken($token)->withHeader('If-Match', '"'.$ver.'"')
        ->patchJson("/api/v1/marketing/design-requests/$id", ['refs' => [
            $refs[1], $refs[2], ['t' => 'img', 'v' => DR_FOTO_C, 'n' => 'c.png'],
        ]])->assertOk();

    $full = drRefs($token, $id);
    expect(array_column($full, 'n'))->toBe(['contoh', 'b.jpg', 'c.png'])
        ->and($full[1]['v'])->toBe(DR_FOTO_B)
        ->and($full[2]['v'])->toBe(DR_FOTO_C)
        ->and(array_filter($full, fn ($r) => isset($r['dipangkas'])))->toBe([]);
});

it('an unchanged row from /state is not a conflict against a newer version', function () {
    $token = loginAs(officeUser('u-aurel'));
    [$id, $ver] = drBuat($token);
    $row = drDariState($token, $id);

    // Same content, stale version: the stubs must be restored BEFORE the
    // comparison, or this unchanged save is reported as a conflict.
    $this->withToken($token)->withHeader('If-Match', '"'.($ver - 1).'"')
        ->patchJson("/api/v1/marketing/design-requests/$id", $row)->assertOk();
});

it('a new request carrying stubs stores no empty image', function () {
    $token = loginAs(officeUser('u-aurel'));
    $id = $this->withToken($token)->postJson('/api/v1/marketing/design-requests', [
        'judul' => 'Salinan', 'jenis' => 'design',
        'refs' => [['t' => 'img', 'n' => 'x.png', 'dipangkas' => true], ['t' => 'img', 'v' => DR_FOTO_C, 'n' => 'c.png']],
    ])->assertCreated()->json('data.id');

    expect(drRefs($token, $id))->toBe([['t' => 'img', 'v' => DR_FOTO_C, 'n' => 'c.png']]);
});
