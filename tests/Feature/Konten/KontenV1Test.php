<?php

use App\Support\Modules;
use App\Support\RowSync;

require_once __DIR__.'/helpers.php';

/* u-andry holds module `konten`; u-adit does not. */

beforeEach(function () {
    config(['laksamana.modules.konten.data_dir' => storage_path('framework/testing/konten-db')]);
});

it('requires the konten module', function () {
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/konten/content')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('requires login', function () {
    $this->getJson('/api/v1/konten/content')->assertStatus(401);
});

it('lists content with paging meta and filters', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/konten/content?perPage=5')
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.perPage', 5);

    foreach ($this->withToken($token)->getJson('/api/v1/konten/content?status=Posted&perPage=100')->assertOk()->json('data') as $row) {
        expect($row['status'])->toBe('Posted');
    }
});

it('creates a record, stamps a version and logs the activity', function () {
    $res = $this->withToken(loginAs(officeUser('u-andry')))
        ->postJson('/api/v1/konten/brands', ['name' => 'Brand API'])
        ->assertCreated();
    $id = $res->json('data.id');
    expect($id)->toStartWith('b_')->and($res->json('meta.version'))->toBeGreaterThan(0);

    $row = Modules::db('konten')->selectOne(ktSql('SELECT name, updated_at FROM brands WHERE id = ?'), [$id]);
    expect($row->name)->toBe('Brand API')->and((int) $row->updated_at)->toBe($res->json('meta.version'));

    $act = Modules::db('konten')->selectOne(ktSql('SELECT action, by_user FROM logs WHERE ref_id = ? ORDER BY at_ms DESC LIMIT 1'), ['Brand API']);
    expect($act->action)->toBe('Brand ditambah (API)')->and($act->by_user)->toBe(officeUser('u-andry')['name']);
});

it('rejects a duplicate id with 409', function () {
    $token = loginAs(officeUser('u-andry'));
    $id = 'b_dupe_'.strtolower(Str::random(6));
    $this->withToken($token)->postJson('/api/v1/konten/brands', ['id' => $id, 'name' => 'Satu'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/konten/brands', ['id' => $id, 'name' => 'Dua'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_exists');
});

it('requires a version to update', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->patchJson('/api/v1/konten/brands/b_mrbwdt83an0o', ['name' => 'X'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');
});

it('rejects a stale version with 409 and the current record', function () {
    $this->withToken(loginAs(officeUser('u-andry')))
        ->withHeader('If-Match', '"1"')->patchJson('/api/v1/konten/brands/b_mrbwdt83an0o', ['name' => 'X'])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')
        ->assertJsonPath('error.details.current.id', 'b_mrbwdt83an0o');
});

it('merges a PATCH with the right version and bumps it', function () {
    $row = Modules::db('konten')->selectOne(ktSql("SELECT updated_at FROM brands WHERE id='b_mrbwdt83an0o'"));
    $oldName = json_decode(Modules::db('konten')->selectOne(ktSql("SELECT data FROM brands WHERE id='b_mrbwdt83an0o'"))->data, true)['name'];
    $res = $this->withToken(loginAs(officeUser('u-andry')))
        ->withHeader('If-Match', '"'.((int) $row->updated_at).'"')->patchJson('/api/v1/konten/brands/b_mrbwdt83an0o', ['name' => $oldName.' v2'])
        ->assertOk()->assertJsonPath('data.name', $oldName.' v2');
    expect($res->json('meta.version'))->toBeGreaterThan((int) $row->updated_at);
});

it('deletes with the right version', function () {
    $token = loginAs(officeUser('u-andry'));
    $id = $this->withToken($token)->postJson('/api/v1/konten/brands', ['name' => 'Hapus Saya'])->assertCreated()->json('data.id');
    $v = $this->withToken($token)->getJson('/api/v1/konten/brands/'.$id)->json('meta.version');
    $this->withToken($token)->withHeader('If-Match', '"'.$v.'"')->deleteJson('/api/v1/konten/brands/'.$id)
        ->assertOk()->assertJsonPath('data.deleted', true);
    $this->withToken($token)->getJson('/api/v1/konten/brands/'.$id)->assertStatus(404);
});

it('stays compatible with old laksamana-office tabs: the v1 version is a valid baseUpdatedAt', function () {
    $v = (int) Modules::db('konten')->selectOne(ktSql("SELECT updated_at FROM brands WHERE id='b_mrbwdt83an0o'"))->updated_at;
    $new = $this->withToken(loginAs(officeUser('u-andry')))
        ->withHeader('If-Match', '"'.$v.'"')->patchJson('/api/v1/konten/brands/b_mrbwdt83an0o', ['name' => 'Brand v1'])
        ->json('meta.version');

    $row = json_decode(Modules::db('konten')->selectOne(ktSql("SELECT data FROM brands WHERE id='b_mrbwdt83an0o'"))->data, true);
    $row['name'] = 'Brand legacy';
    $row['baseUpdatedAt'] = $new; // what the old frontend reads back
    $row['updatedAt'] = $new + 5;
    $r = test()->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => ['brands' => [$row]]])->json();

    expect($r['data']['bentrok'])->toBe([]);
});

it('merges per-platform performance into the content row', function () {
    $token = loginAs(officeUser('u-andry'));
    $id = $this->withToken($token)->postJson('/api/v1/konten/content',
        ['title' => 'Konten Perf', 'platform' => 'IG', 'status' => 'Posted'])->assertCreated()->json('data.id');

    $res = $this->withToken($token)->putJson('/api/v1/konten/content/'.$id.'/performance',
        ['platform' => 'IG', 'metrics' => ['reach' => 1000, 'likes' => 50]])
        ->assertOk()->assertJsonPath('data.perf.IG.reach', 1000);
    expect($res->json('data.perf.IG.by'))->toBe(officeUser('u-andry')['name']);

    $this->withToken($token)->putJson('/api/v1/konten/content/'.$id.'/performance', ['platform' => ''])
        ->assertStatus(422);
});

it('removes one platform entry when metrics is null and keeps the others', function () {
    $token = loginAs(officeUser('u-andry'));
    $id = $this->withToken($token)->postJson('/api/v1/konten/content',
        ['title' => 'Konten Hapus Perf', 'platform' => 'IG', 'status' => 'Posted'])->assertCreated()->json('data.id');

    $this->withToken($token)->putJson('/api/v1/konten/content/'.$id.'/performance',
        ['platform' => 'IG', 'metrics' => ['reach' => 1000]])->assertOk();
    $this->withToken($token)->putJson('/api/v1/konten/content/'.$id.'/performance',
        ['platform' => 'TT', 'metrics' => ['reach' => 200]])->assertOk();

    $res = $this->withToken($token)->putJson('/api/v1/konten/content/'.$id.'/performance',
        ['platform' => 'IG', 'metrics' => null])
        ->assertOk();
    expect($res->json('data.perf'))->not->toHaveKey('IG')
        ->and($res->json('data.perf.TT.reach'))->toBe(200);

    // Deleting a platform that is not there changes nothing.
    $v = $res->json('meta.version');
    $this->withToken($token)->putJson('/api/v1/konten/content/'.$id.'/performance',
        ['platform' => 'IG', 'metrics' => null])
        ->assertOk()->assertJsonPath('meta.version', $v);
});

it('keeps an emptied perf a JSON object on every read, v1 and legacy (#261)', function () {
    $token = loginAs(officeUser('u-andry'));
    $id = $this->withToken($token)->postJson('/api/v1/konten/content',
        ['title' => 'Konten Perf Objek', 'platform' => 'IG', 'status' => 'Posted'])->assertCreated()->json('data.id');
    $this->withToken($token)->putJson('/api/v1/konten/content/'.$id.'/performance',
        ['platform' => 'IG', 'metrics' => ['reach' => 1000]])->assertOk();

    // assertJson* cannot tell {} from [] (both decode to an empty array): read the raw body.
    $put = $this->withToken($token)->putJson('/api/v1/konten/content/'.$id.'/performance',
        ['platform' => 'IG', 'metrics' => null])->assertOk();
    expect(json_decode($put->getContent())->data->perf)->toBeInstanceOf(stdClass::class);

    $show = $this->withToken($token)->getJson('/api/v1/konten/content/'.$id)->assertOk();
    expect(json_decode($show->getContent())->data->perf)->toBeInstanceOf(stdClass::class);

    $all = json_decode($this->get('/konten-api-mysql/api.php?action=getAll')->assertOk()->getContent());
    $row = collect($all->data->content)->first(fn ($c) => ($c->id ?? null) === $id);
    expect($row)->not->toBeNull()->and($row->perf)->toBeInstanceOf(stdClass::class);
});

it('serves the bootstrap state and diagnostics', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/konten/state')->assertOk()
        ->assertJsonStructure(['data' => ['users', 'brands', 'content', 'logs', 'settings']]);
    $this->withToken($token)->getJson('/api/v1/konten/stats')->assertOk()
        ->assertJsonStructure(['data' => ['content', 'blobChars', 'berkas']]);
});

it('reads and appends the activity trail', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->getJson('/api/v1/konten/logs?limit=3')->assertOk()->assertJsonCount(3, 'data');
    $this->withToken($token)->postJson('/api/v1/konten/logs', ['action' => 'uji API', 'target' => 'x'])
        ->assertCreated()->assertJsonPath('data.action', 'uji API');
});

it('versions settings documents by content hash', function () {
    $token = loginAs(officeUser('u-andry'));
    $doc = $this->withToken($token)->getJson('/api/v1/konten/documents/settings')->assertOk();
    $this->withToken($token)->withHeader('If-Match', '"stale"')->putJson('/api/v1/konten/documents/settings', ['a' => 1])
        ->assertStatus(409);
    $cur = $doc->json('data');
    $cur['v1probe'] = 1;
    $this->withToken($token)->withHeader('If-Match', '"'.$doc->json('meta.version').'"')->putJson('/api/v1/konten/documents/settings', $cur)
        ->assertOk()->assertJsonPath('data.v1probe', 1);
    $this->withToken($token)->getJson('/api/v1/konten/documents/tidak-ada')->assertStatus(404);
});

it('uploads and serves a file', function () {
    $token = loginAs(officeUser('u-andry'));
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $key = $this->withToken($token)->postJson('/api/v1/konten/files', ['dataBase64' => $png, 'mimeType' => 'image/png', 'fileName' => 'dot.png'])
        ->assertCreated()->json('data.key');
    expect($key)->toStartWith('rc_')->toEndWith('.png');
    $this->withToken($token)->get('/api/v1/konten/files/'.$key)->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('exposes every ported collection through generic CRUD', function () {
    $token = loginAs(officeUser('u-andry'));
    foreach (['users', 'brands', 'campaigns', 'content', 'prod-tasks', 'shootings', 'assets', 'bank', 'kols', 'visits', 'ads', 'ad-funds', 'notifications'] as $res) {
        $this->withToken($token)->getJson('/api/v1/konten/'.$res.'?perPage=2')->assertOk()->assertJsonStructure(['data', 'meta']);
    }
});

it('replaces the whole record on PUT and drops fields that are not sent', function () {
    $token = loginAs(officeUser('u-andry'));
    $id = $this->withToken($token)->postJson('/api/v1/konten/brands', ['name' => 'Put Saya', 'desc' => 'lama'])
        ->assertCreated()->json('data.id');
    $v = $this->withToken($token)->getJson('/api/v1/konten/brands/'.$id)->json('meta.version');

    $res = $this->withToken($token)->withHeader('If-Match', '"'.$v.'"')
        ->putJson('/api/v1/konten/brands/'.$id, ['name' => 'Put Baru'])
        ->assertOk()->assertJsonPath('data.name', 'Put Baru');
    expect($res->json('data'))->not->toHaveKey('desc')
        ->and($res->json('meta.version'))->toBeGreaterThan($v);
});

it('treats an identical-content write as a no-op success instead of a conflict', function () {
    $token = loginAs(officeUser('u-andry'));
    $row = $this->withToken($token)->postJson('/api/v1/konten/brands', ['name' => 'Sama Saja'])
        ->assertCreated()->json('data');
    $v = $this->withToken($token)->getJson('/api/v1/konten/brands/'.$row['id'])->json('meta.version');

    // Someone else saves first: the stored version moves, the content does not.
    $db = Modules::db('konten');
    $cur = json_decode($db->selectOne(ktSql('SELECT data FROM brands WHERE id = ?'), [$row['id']])->data, true);
    $cur['updatedAt'] = $v + 5;
    $db->update(ktSql('UPDATE brands SET data = ?, updated_at = ? WHERE id = ?'),
        [RowSync::enc($cur), $v + 5, $row['id']]);

    // Sending back exactly what is stored is a no-op 200, not a 409.
    $this->withToken($token)->withHeader('If-Match', '"'.$v.'"')
        ->patchJson('/api/v1/konten/brands/'.$row['id'], ['name' => 'Sama Saja'])
        ->assertOk()->assertJsonPath('data.id', $row['id']);
});

it('deletes with ?version= as well as If-Match, and 404s unknown ids', function () {
    $token = loginAs(officeUser('u-andry'));
    $id = $this->withToken($token)->postJson('/api/v1/konten/brands', ['name' => 'Hapus Query'])
        ->assertCreated()->json('data.id');
    $v = $this->withToken($token)->getJson('/api/v1/konten/brands/'.$id)->json('meta.version');

    $this->withToken($token)->deleteJson('/api/v1/konten/brands/'.$id.'?version='.$v)
        ->assertOk()->assertJsonPath('data.deleted', true);
    $this->withToken($token)->getJson('/api/v1/konten/brands/'.$id)->assertStatus(404);
    $this->withToken($token)->withHeader('If-Match', '"'.$v.'"')
        ->deleteJson('/api/v1/konten/brands/tidak-ada')->assertStatus(404);
});

it('filters by free text and by updatedSince', function () {
    $token = loginAs(officeUser('u-andry'));
    $name = 'Cari Saya '.strtolower(Str::random(6));
    $made = $this->withToken($token)->postJson('/api/v1/konten/brands', ['name' => $name])
        ->assertCreated()->json('data');

    $hit = $this->withToken($token)->getJson('/api/v1/konten/brands?q='.urlencode($name))->assertOk()->json('data');
    expect(collect($hit)->pluck('id')->all())->toContain($made['id']);

    $v = $this->withToken($token)->getJson('/api/v1/konten/brands/'.$made['id'])->json('meta.version');
    $since = $this->withToken($token)->getJson('/api/v1/konten/brands?updatedSince='.($v - 1))->assertOk()->json('data');
    expect(collect($since)->pluck('id')->all())->toContain($made['id']);
    $after = $this->withToken($token)->getJson('/api/v1/konten/brands?updatedSince='.$v)->assertOk()->json('data');
    expect(collect($after)->pluck('id')->all())->not->toContain($made['id']);
});
