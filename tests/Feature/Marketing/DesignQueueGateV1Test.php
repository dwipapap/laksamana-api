<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;

require_once __DIR__.'/helpers.php';

/*
 * G-16: holders of `konten` reach the four production-queue routes without
 * holding `marketing` — the old Konten page called the legacy designReqs /
 * designReq / designReqSet / designReqOpsi actions, which had no module gate.
 * Creating, replacing or deleting design requests stays marketing-only.
 * The users are made here, inside the test transaction, holding exactly one
 * module each.
 */

beforeEach(function () {
    config(['laksamana.modules.marketing.data_dir' => storage_path('framework/testing/marketing-db')]);
});

/** A throwaway active user holding exactly the given modules. */
function dqUser(string $id, array $modules): array
{
    $repo = app(AccountRepository::class);
    $repo->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => '']);
    foreach ($modules as $m) {
        $repo->upsertGrant($id, $m, true, 'test');
    }
    app(OfficeAccess::class)->forgetUser($id);

    return officeUser($id);
}

/**
 * Token for a user, dropping the guard's cached user: this file switches
 * identity inside one test, and the guard would otherwise keep answering as
 * whoever made the previous request.
 */
function dqToken(array $u): string
{
    // After the login, not before: the login request itself carries the previous
    // test()->withToken() header, so the guard caches that user again.
    $token = loginAs($u);
    app('auth')->forgetGuards();

    return $token;
}

/** A fresh design request made by a marketing holder; returns its id. */
function dqRequest(string $by): string
{
    return test()->withToken(dqToken(dqUser($by, ['marketing'])))->postJson('/api/v1/marketing/design-requests', [
        'judul' => 'Desain Uji', 'brief' => 'Tolong buatkan', 'jenis' => 'design',
    ])->assertCreated()->json('data.id');
}

it('G-16: konten holders use the four production-queue routes', function () {
    $id = dqRequest('u-g16-mkt');
    $token = dqToken(dqUser('u-g16-konten', ['konten']));

    $this->withToken($token)->getJson('/api/v1/marketing/design-queue?active=1')->assertOk()
        ->assertJsonStructure(['data' => ['reqs', 'opsi']]);
    $this->withToken($token)->getJson("/api/v1/marketing/design-requests/$id")->assertOk()
        ->assertJsonPath('data.req.id', $id)->assertJsonPath('data.req.judul', 'Desain Uji');
    $this->withToken($token)->putJson("/api/v1/marketing/design-requests/$id/progress", ['status' => 'done'])
        ->assertOk()->assertJsonPath('data.status', 'done');
    $this->withToken($token)->putJson('/api/v1/marketing/design-options',
        ['brands' => [['id' => 'b-kanal', 'name' => 'Kanal']], 'pics' => [], 'platforms' => []])
        ->assertOk()->assertJsonPath('data.disimpan', true);
    $this->withToken($token)->getJson('/api/v1/marketing/design-requests/tidak-ada')->assertStatus(404);
});

it('G-16: marketing holders keep all four routes', function () {
    $id = dqRequest('u-g16-mkt2');
    $token = dqToken(dqUser('u-g16-mkt3', ['marketing']));

    $this->withToken($token)->getJson('/api/v1/marketing/design-queue')->assertOk();
    $this->withToken($token)->getJson("/api/v1/marketing/design-requests/$id")->assertOk();
    $this->withToken($token)->putJson("/api/v1/marketing/design-requests/$id/progress", ['status' => 'todo'])
        ->assertOk();
    $this->withToken($token)->putJson('/api/v1/marketing/design-options', ['brands' => [], 'pics' => []])
        ->assertOk()->assertJsonPath('data.disimpan', false);
});

it('G-16: holders of neither module get 403 on all four routes', function () {
    $id = dqRequest('u-g16-mkt4');
    $token = dqToken(dqUser('u-g16-luar', []));

    $this->withToken($token)->getJson('/api/v1/marketing/design-queue')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
    $this->withToken($token)->getJson("/api/v1/marketing/design-requests/$id")
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
    $this->withToken($token)->putJson("/api/v1/marketing/design-requests/$id/progress", ['status' => 'done'])
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
    $this->withToken($token)->putJson('/api/v1/marketing/design-options', ['brands' => []])
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('G-16: design-request record CRUD stays marketing-only', function () {
    $id = dqRequest('u-g16-mkt5');
    $token = dqToken(dqUser('u-g16-konten5', ['konten']));

    $this->withToken($token)->postJson('/api/v1/marketing/design-requests', ['judul' => 'x'])
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
    $this->withToken($token)->getJson('/api/v1/marketing/design-requests')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
    $this->withToken($token)->patchJson("/api/v1/marketing/design-requests/$id", ['judul' => 'x'])
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
    $this->withToken($token)->deleteJson("/api/v1/marketing/design-requests/$id")
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});
