<?php

require_once __DIR__.'/helpers.php';

use App\Support\Modules;

/* u-novi holds module `bd`; u-adit does not. */

it('requires login and the bd module', function () {
    $this->getJson('/api/v1/bd/tasks')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/bd/tasks')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('lists records and the full state', function () {
    $token = loginAs(officeUser('u-novi'));
    $n = (int) Modules::db('bd')->selectOne(bdSql('SELECT COUNT(*) c FROM purchase_orders'))->c;
    $this->withToken($token)->getJson('/api/v1/bd/purchase-orders')->assertOk()->assertJsonPath('meta.total', $n);
    $this->withToken($token)->getJson('/api/v1/bd/state')->assertOk()->assertJsonStructure(['data' => ['tasks', 'po', 'promos', '_serverTs']]);
});

it('creates, patches and deletes a task with version checks', function () {
    $token = loginAs(officeUser('u-novi'));
    $res = $this->withToken($token)->postJson('/api/v1/bd/tasks', ['name' => 'API task', 'pics' => ['p9'], 'deadline' => '2026-12-01'])->assertCreated();
    $id = $res->json('data.id');
    $v1 = $res->json('meta.version');
    expect($id)->toMatch('/^t[0-9a-z]{7}$/')->and($res->json('data.updatedAt'))->toBe($v1);
    expect(Modules::db('bd')->selectOne(bdSql('SELECT pic, deadline, updated_at FROM tasks WHERE id = ?'), [$id]))
        ->pic->toBe('p9')->deadline->toBe('2026-12-01')->updated_at->toBe($v1);

    $this->withToken($token)->patchJson("/api/v1/bd/tasks/$id", ['status' => 'Done'])->assertStatus(428);
    $this->withToken($token)->patchJson("/api/v1/bd/tasks/$id?version=1", ['status' => 'Done'])
        ->assertStatus(409)->assertJsonPath('error.details.current.id', $id);
    $v2 = $this->withToken($token)->patchJson("/api/v1/bd/tasks/$id?version=$v1", ['status' => 'Done'])
        ->assertOk()->assertJsonPath('data.name', 'API task')->assertJsonPath('data.status', 'Done')->json('meta.version');
    expect($v2)->toBeGreaterThan($v1);

    $this->withToken($token)->deleteJson("/api/v1/bd/tasks/$id?version=$v1")->assertStatus(409);
    $this->withToken($token)->deleteJson("/api/v1/bd/tasks/$id?version=$v2")->assertOk();
    expect(Modules::db('bd')->selectOne(bdSql('SELECT id FROM tasks WHERE id = ?'), [$id]))->toBeNull();
});

it('adds purchase orders in batch and records a realisation as the session user', function () {
    $token = loginAs(officeUser('u-novi'));
    $ids = $this->withToken($token)->postJson('/api/v1/bd/purchase-orders/batch', ['items' => [['item' => 'Tinta', 'amount' => 5000]]])
        ->assertCreated()->assertJsonPath('data.added', 1)->json('data.ids');

    $res = $this->withToken($token)->putJson("/api/v1/bd/purchase-orders/{$ids[0]}/realisasi", ['realisasi' => 4800, 'oleh' => 'spoofed'])
        ->assertOk()->assertJsonPath('data.result.realisasi', 4800)->assertJsonPath('data.record.status', 'Diterima');
    expect($res->json('data.record.prosesBy'))->toBe(officeUser('u-novi')['name']);

    $this->withToken($token)->postJson('/api/v1/bd/purchase-orders/batch', ['items' => []])->assertStatus(422);
    $this->withToken($token)->putJson('/api/v1/bd/purchase-orders/nope/realisasi', ['realisasi' => 1])->assertStatus(404);
});

it('edits the promos document with its version', function () {
    $token = loginAs(officeUser('u-novi'));
    $v = $this->withToken($token)->getJson('/api/v1/bd/settings')->assertOk()->json('meta.versions.promos');
    $this->withToken($token)->withHeader('If-Match', 'stale')->putJson('/api/v1/bd/settings/promos', ['value' => []])->assertStatus(409);
    $this->flushHeaders();
    $this->withToken($token)->withHeader('If-Match', $v)->putJson('/api/v1/bd/settings/promos', ['value' => [['id' => 'pm1', 'nama' => 'Promo']]])
        ->assertOk()->assertJsonPath('data.0.nama', 'Promo');
    expect(Modules::db('bd')->selectOne(bdSql("SELECT v FROM settings WHERE k='promos'"))->v)->toBe('[{"id":"pm1","nama":"Promo"}]');
});
