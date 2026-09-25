<?php

use App\Support\Modules;

/* u-jb holds module `howandi_life`; u-adit does not. */

it('requires login and the howandi_life module', function () {
    $this->getJson('/api/v1/hlife/tasks')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/hlife/tasks')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('serves the full state and per-collection lists with versions', function () {
    $token = loginAs(officeUser('u-jb'));
    $state = $this->withToken($token)->getJson('/api/v1/hlife/state')->assertOk()->json('data');
    expect($state)->toHaveKeys(['tasks', 'finance', 'auth', 'channels']);

    $res = $this->withToken($token)->getJson('/api/v1/hlife/tasks')->assertOk();
    expect($res->json('meta.total'))->toBe(count($state['tasks']))
        ->and($res->json('meta.versions'))->toHaveCount(count($state['tasks']));
});

it('creates, reads, patches and deletes a record with version checks', function () {
    $token = loginAs(officeUser('u-jb'));
    $created = $this->withToken($token)->postJson('/api/v1/hlife/tasks', ['name' => 'API task', 'done' => false, 'meta' => new stdClass])
        ->assertCreated();
    $id = $created->json('data.id');
    $v1 = $created->json('meta.version');
    expect($id)->toMatch('/^[0-9a-z]{7}$/');

    $db = Modules::db('hlife');
    expect($db->selectOne('SELECT nama, data FROM tasks WHERE id = ?', [$id]))
        ->nama->toBe('API task')
        ->data->toContain('"meta":{}');

    $this->withToken($token)->patchJson("/api/v1/hlife/tasks/$id", ['done' => true])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');
    $this->withToken($token)->withHeader('If-Match', '"stale"')->patchJson("/api/v1/hlife/tasks/$id", ['done' => true])
        ->assertStatus(409)->assertJsonPath('error.details.current.id', $id);

    $patched = $this->withToken($token)->withHeader('If-Match', "\"$v1\"")->patchJson("/api/v1/hlife/tasks/$id", ['done' => true])
        ->assertOk()->assertJsonPath('data.name', 'API task')->assertJsonPath('data.done', true);
    $v2 = $patched->json('meta.version');
    expect($v2)->not->toBe($v1)
        ->and((int) $db->selectOne('SELECT done FROM tasks WHERE id = ?', [$id])->done)->toBe(1);

    $this->flushHeaders(); // withHeader() persists; test the ?version= form
    $this->withToken($token)->deleteJson("/api/v1/hlife/tasks/$id?version=$v1")->assertStatus(409);
    $this->withToken($token)->deleteJson("/api/v1/hlife/tasks/$id?version=$v2")->assertOk()->assertJsonPath('data.deleted', true);
    expect($db->selectOne('SELECT id FROM tasks WHERE id = ?', [$id]))->toBeNull();
});

it('replaces a record with PUT and rejects a duplicate id or mismatched body id', function () {
    $token = loginAs(officeUser('u-jb'));
    $v = $this->withToken($token)->postJson('/api/v1/hlife/ledger', ['id' => 'led-t1', 'month' => '2026-09', 'income' => 10, 'note' => 'x'])
        ->assertCreated()->json('meta.version');
    $this->withToken($token)->postJson('/api/v1/hlife/ledger', ['id' => 'led-t1'])->assertStatus(409)->assertJsonPath('error.code', 'already_exists');
    $this->withToken($token)->withHeader('If-Match', $v)->putJson('/api/v1/hlife/ledger/led-t1', ['id' => 'other'])->assertStatus(422);

    $this->withToken($token)->withHeader('If-Match', $v)->putJson('/api/v1/hlife/ledger/led-t1', ['month' => '2026-10', 'income' => 3])
        ->assertOk()->assertJsonPath('data', ['month' => '2026-10', 'income' => 3, 'id' => 'led-t1']);
    expect(Modules::db('hlife')->selectOne("SELECT bulan, income FROM ledger WHERE id='led-t1'"))
        ->bulan->toBe('2026-10')->income->toEqual(3);
});

it('reads and writes a setting with its version', function () {
    $token = loginAs(officeUser('u-jb'));
    $all = $this->withToken($token)->getJson('/api/v1/hlife/settings')->assertOk();
    expect($all->json('data'))->toHaveKeys(['firstRun', 'mood', 'energy', 'focus', 'weeklyTarget', 'auth', 'channels', 'dump']);

    $v = $all->json('meta.versions.channels');
    $this->withToken($token)->withHeader('If-Match', 'stale')->putJson('/api/v1/hlife/settings/channels', ['value' => ['A']])->assertStatus(409);
    $this->withToken($token)->withHeader('If-Match', $v)->putJson('/api/v1/hlife/settings/channels', ['value' => ['A', 'B']])
        ->assertOk()->assertJsonPath('data', ['A', 'B']);
    expect(Modules::db('hlife')->selectOne("SELECT v FROM settings WHERE k='channels'")->v)->toBe('["A","B"]');

    $this->withToken($token)->getJson('/api/v1/hlife/settings/nope')->assertStatus(404);
});

it('denies a superadmin whose howandi_life grant is revoked, like the page gate', function () {
    $this->withToken(loginAs(officeUser('u-wandi')))->getJson('/api/v1/hlife/state')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});
