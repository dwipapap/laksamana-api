<?php

use App\Support\Modules;

/* u-andry holds module `akademi`; u-lusi does not. u-rizkiarfan admins it. */

beforeEach(function () {
    config(['laksamana.modules.akademi.data_dir' => storage_path('framework/testing/akademi-db')]);
});

it('requires the akademi module', function () {
    $this->withToken(loginAs(officeUser('u-lusi')))->getJson('/api/v1/akademi/materials')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('requires login', function () {
    $this->getJson('/api/v1/akademi/materials')->assertStatus(401);
});

it('lists materials with paging meta and filters', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/akademi/materials?perPage=5')
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.perPage', 5);

    foreach ($this->withToken($token)->getJson('/api/v1/akademi/materials?published=1&perPage=100')->assertOk()->json('data') as $row) {
        expect($row['published'])->toBeTrue();
    }
});

it('refuses record writes without admin rights', function () {
    $this->withToken(loginAs(officeUser('u-andry')))
        ->postJson('/api/v1/akademi/divisions', ['name' => 'X'])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('creates a division, stamps a version and logs the activity', function () {
    $res = $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->postJson('/api/v1/akademi/divisions', ['name' => 'Divisi API'])
        ->assertCreated();
    $id = $res->json('data.id');
    expect($id)->toStartWith('d_')->and($res->json('meta.version'))->toBeGreaterThan(0);

    $row = Modules::db('akademi')->selectOne('SELECT name, updated_at FROM divisions WHERE id = ?', [$id]);
    expect($row->name)->toBe('Divisi API')->and((int) $row->updated_at)->toBe($res->json('meta.version'));

    $act = Modules::db('akademi')->selectOne('SELECT action, user_id FROM activity WHERE action = ? ORDER BY ts DESC LIMIT 1', ['tambah_divisi']);
    expect($act->user_id)->toBe('u-rizkiarfan');
});

it('rejects a duplicate id with 409', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $id = 'd_dupe_'.strtolower(Str::random(6));
    $this->withToken($token)->postJson('/api/v1/akademi/divisions', ['id' => $id, 'name' => 'Satu'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/akademi/divisions', ['id' => $id, 'name' => 'Dua'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_exists');
});

it('requires a version to update', function () {
    $this->withToken(loginAs(officeUser('u-rizkiarfan')))->patchJson('/api/v1/akademi/divisions/d_x', ['name' => 'X'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');
});

it('rejects a stale version with 409 and the current record', function () {
    $id = $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->postJson('/api/v1/akademi/divisions', ['name' => 'Konflik'])->assertCreated()->json('data.id');
    $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->withHeader('If-Match', '"1"')->patchJson('/api/v1/akademi/divisions/'.$id, ['name' => 'X'])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')
        ->assertJsonPath('error.details.current.id', $id);
});

it('merges a PATCH with the right version and bumps it', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $id = $this->withToken($token)->postJson('/api/v1/akademi/divisions', ['name' => 'Bump'])->assertCreated()->json('data.id');
    $v = $this->withToken($token)->getJson('/api/v1/akademi/divisions/'.$id)->json('meta.version');
    $res = $this->withToken($token)
        ->withHeader('If-Match', '"'.$v.'"')->patchJson('/api/v1/akademi/divisions/'.$id, ['name' => 'Bump v2'])
        ->assertOk()->assertJsonPath('data.name', 'Bump v2');
    expect($res->json('meta.version'))->toBeGreaterThan($v);
});

it('deletes with the right version', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $id = $this->withToken($token)->postJson('/api/v1/akademi/divisions', ['name' => 'Hapus Saya'])->assertCreated()->json('data.id');
    $v = $this->withToken($token)->getJson('/api/v1/akademi/divisions/'.$id)->json('meta.version');
    $this->withToken($token)->withHeader('If-Match', '"'.$v.'"')->deleteJson('/api/v1/akademi/divisions/'.$id)
        ->assertOk()->assertJsonPath('data.deleted', true);
    $this->withToken($token)->getJson('/api/v1/akademi/divisions/'.$id)->assertStatus(404);
});

it('stays compatible with old laksamana-office tabs: the v1 version is a valid baseUpdatedAt', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $id = $this->withToken($token)->postJson('/api/v1/akademi/divisions', ['name' => 'Kompat'])->assertCreated()->json('data.id');
    $v = $this->withToken($token)->getJson('/api/v1/akademi/divisions/'.$id)->json('meta.version');

    $row = json_decode(Modules::db('akademi')->selectOne('SELECT data FROM divisions WHERE id = ?', [$id])->data, true);
    $row['name'] = 'Kompat legacy';
    $row['baseUpdatedAt'] = $v; // what the old frontend reads back
    $row['updatedAt'] = $v + 5;
    $r = test()->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => ['divisions' => [$row]]])->json();

    expect($r['data']['bentrok'])->toBe([]);
});

it('writes and reads one progress cell with versions', function () {
    $token = loginAs(officeUser('u-andry'));

    $this->withToken($token)->putJson('/api/v1/akademi/progress/u-andry/m_prog_v1', ['status' => 'done'])
        ->assertOk()->assertJsonPath('data.status', 'done');
    $v = $this->withToken($token)->getJson('/api/v1/akademi/progress/u-andry/m_prog_v1')->json('meta.version');

    $this->withToken($token)->withHeader('If-Match', '"1"')
        ->putJson('/api/v1/akademi/progress/u-andry/m_prog_v1', ['status' => 'x'])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');

    $this->withToken($token)->withHeader('If-Match', '"'.$v.'"')
        ->putJson('/api/v1/akademi/progress/u-andry/m_prog_v1', ['status' => 'done', 'note' => 'v2'])
        ->assertOk()->assertJsonPath('data.note', 'v2');

    $this->withToken($token)->withHeader('If-Match', '"'.$v.'"')
        ->deleteJson('/api/v1/akademi/progress/u-andry/m_prog_v1')
        ->assertStatus(409); // bumped above $v by the update
});

it('serves training stats globally and per user', function () {
    $token = loginAs(officeUser('u-andry'));
    $all = $this->withToken($token)->getJson('/api/v1/akademi/training-stats')->assertOk()->json('data');
    expect($all)->toBeArray();

    $uid = array_key_first($all);
    $this->withToken($token)->getJson('/api/v1/akademi/training-stats/'.$uid)->assertOk()
        ->assertJsonPath('data.mandPct', $all[$uid]['mandPct']);

    $this->withToken($token)->getJson('/api/v1/akademi/training-stats/u-tidak-ada')->assertStatus(404);
});

it('serves the bootstrap state and diagnostics', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/akademi/state')->assertOk()
        ->assertJsonStructure(['data' => ['users', 'divisions', 'materials', 'programs', 'progress', 'activity', 'settings']]);
    $this->withToken($token)->getJson('/api/v1/akademi/stats')->assertOk()
        ->assertJsonStructure(['data' => ['materials', 'blobChars', 'berkas']]);
});

it('reads and appends the activity trail', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/akademi/activity?limit=3')->assertOk()->assertJsonCount(3, 'data');

    $this->withToken($token)->postJson('/api/v1/akademi/activity', ['action' => 'uji_api', 'detail' => 'v1'])
        ->assertCreated()->assertJsonPath('data.action', 'uji_api');
});

it('reads and writes settings documents with hash versions', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $doc = $this->withToken($token)->getJson('/api/v1/akademi/documents/settings')->assertOk()->json();
    expect($doc['meta']['version'])->toBeString();

    $this->withToken($token)->putJson('/api/v1/akademi/documents/settings?version=stale', $doc['data'])
        ->assertStatus(409);

    $this->withToken($token)->putJson('/api/v1/akademi/documents/settings?version='.$doc['meta']['version'], $doc['data'])
        ->assertOk();
});

it('uploads and streams a file', function () {
    $token = loginAs(officeUser('u-andry'));
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $out = $this->withToken($token)->postJson('/api/v1/akademi/files',
        ['dataBase64' => 'data:image/png;base64,'.$png, 'mimeType' => 'image/png', 'fileName' => 'dot.png'])
        ->assertCreated()->json();
    expect($out['data']['key'])->toStartWith('rc_')->and($out['data']['url'])->toContain('/api/v1/akademi/files/');

    $this->withToken($token)->get('/api/v1/akademi/files/'.$out['data']['key'])
        ->assertOk()->assertHeader('Content-Type', 'image/png');
});
