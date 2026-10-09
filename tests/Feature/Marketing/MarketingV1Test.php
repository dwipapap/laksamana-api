<?php

use App\Support\Modules;

require_once __DIR__.'/helpers.php';

/* u-aurel holds module `marketing` (not superadmin); u-adit does not. */

beforeEach(function () {
    config(['laksamana.modules.marketing.data_dir' => storage_path('framework/testing/marketing-db')]);
});

it('requires the marketing module', function () {
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/marketing/clients')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('lists clients with paging meta', function () {
    $this->withToken(loginAs(officeUser('u-aurel')))->getJson('/api/v1/marketing/clients?perPage=5')
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.perPage', 5);
});

it('creates a record, stamps a version and logs the activity', function () {
    $res = $this->withToken(loginAs(officeUser('u-aurel')))
        ->postJson('/api/v1/marketing/clients', ['nama' => 'Klien API', 'perusahaan' => 'PT API', 'status' => 'Lead'])
        ->assertCreated();
    $id = $res->json('data.id');
    expect($id)->toStartWith('c_')->and($res->json('meta.version'))->toBeGreaterThan(0);

    $row = Modules::db('marketing')->selectOne(mktSql('SELECT nama, status, updated_at FROM clients WHERE id = ?'), [$id]);
    expect($row->nama)->toBe('Klien API')->and((int) $row->updated_at)->toBe($res->json('meta.version'));

    $act = Modules::db('marketing')->selectOne(mktSql('SELECT action, by_user FROM activities WHERE ref_id = ? ORDER BY at_time DESC LIMIT 1'), [$id]);
    expect($act->action)->toBe('Client ditambah (API)')->and($act->by_user)->toBe(officeUser('u-aurel')['name']);
});

it('requires a version to update', function () {
    $this->withToken(loginAs(officeUser('u-aurel')))->patchJson('/api/v1/marketing/clients/c_77uxgd8', ['status' => 'Deal'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');
});

it('rejects a stale version with 409 and the current record', function () {
    $this->withToken(loginAs(officeUser('u-aurel')))
        ->withHeader('If-Match', '"1"')->patchJson('/api/v1/marketing/clients/c_77uxgd8', ['status' => 'Deal'])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')
        ->assertJsonPath('error.details.current.id', 'c_77uxgd8');
});

it('merges a PATCH with the right version and bumps it', function () {
    $row = Modules::db('marketing')->selectOne(mktSql("SELECT updated_at, JSON_UNQUOTE(JSON_EXTRACT(data, '$.nama')) AS nama FROM clients WHERE id='c_77uxgd8'"));
    $res = $this->withToken(loginAs(officeUser('u-aurel')))
        ->withHeader('If-Match', '"'.((int) $row->updated_at).'"')->patchJson('/api/v1/marketing/clients/c_77uxgd8', ['status' => 'Deal'])
        ->assertOk()->assertJsonPath('data.status', 'Deal')->assertJsonPath('data.nama', $row->nama);
    expect($res->json('meta.version'))->toBeGreaterThan((int) $row->updated_at);
});

it('stays compatible with old laksamana-office tabs: the v1 version is a valid baseUpdatedAt', function () {
    $v = (int) Modules::db('marketing')->selectOne(mktSql("SELECT updated_at FROM clients WHERE id='c_77uxgd8'"))->updated_at;
    $new = $this->withToken(loginAs(officeUser('u-aurel')))
        ->withHeader('If-Match', '"'.$v.'"')->patchJson('/api/v1/marketing/clients/c_77uxgd8', ['status' => 'Deal'])
        ->json('meta.version');

    $row = json_decode(Modules::db('marketing')->selectOne(mktSql("SELECT data FROM clients WHERE id='c_77uxgd8'"))->data, true);
    $row['status'] = 'Event Done';
    $row['baseUpdatedAt'] = $new;          // what the old frontend reads back
    $row['updatedAt'] = $new + 5;
    $r = $this->legacyPost('/marketing-api-mysql/api.php', ['action' => 'saveAll', 'data' => ['clients' => [$row]]])->json();

    expect($r['data']['bentrok'])->toBe([]);
});

it('manages Reservasi VIP rows stored inside settings', function () {
    $res = $this->withToken(loginAs(officeUser('u-aurel')))
        ->postJson('/api/v1/marketing/vip', ['nama' => 'VIP API', 'tanggal' => '2026-12-01', 'jenis' => 'Assisted'])
        ->assertCreated();
    $id = $res->json('data.id');
    $vip = mktVipList();
    expect(collect($vip)->firstWhere('id', $id)['nama'])->toBe('VIP API');
});

it('versions settings documents by content hash', function () {
    $token = loginAs(officeUser('u-aurel'));
    $doc = $this->withToken($token)->getJson('/api/v1/marketing/documents/menuDb')->assertOk();
    $this->withToken($token)->withHeader('If-Match', '"stale"')->putJson('/api/v1/marketing/documents/menuDb', ['a' => 1])
        ->assertStatus(409);
    $this->withToken($token)->withHeader('If-Match', '"'.$doc->json('meta.version').'"')->putJson('/api/v1/marketing/documents/menuDb', ['a' => 1])
        ->assertOk()->assertJsonPath('data.a', 1);
});

it('reads and writes the kalkHistori document (Menu Kalkulator history)', function () {
    $token = loginAs(officeUser('u-aurel'));
    $doc = $this->withToken($token)->getJson('/api/v1/marketing/documents/kalkHistori')->assertOk();
    $this->withToken($token)->withHeader('If-Match', '"'.$doc->json('meta.version').'"')
        ->putJson('/api/v1/marketing/documents/kalkHistori', [['id' => 'kh_uji', 'nama' => 'Acara Uji', 'pax' => 100]])
        ->assertOk()->assertJsonPath('data.0.nama', 'Acara Uji');
    $this->withToken($token)->getJson('/api/v1/marketing/documents/kalkHistori')->assertOk()
        ->assertJsonPath('data.0.id', 'kh_uji');
});

it('serves read models for other apps', function () {
    $token = loginAs(officeUser('u-aurel'));
    $this->withToken($token)->getJson('/api/v1/marketing/events-on/2026-09-20')->assertOk()->assertJsonStructure(['data' => ['events', 'vip']]);
});

it('uploads and serves a file', function () {
    $token = loginAs(officeUser('u-aurel'));
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $key = $this->withToken($token)->postJson('/api/v1/marketing/files', ['dataBase64' => $png, 'mimeType' => 'image/png', 'fileName' => 'dot.png'])
        ->assertCreated()->json('data.key');
    expect($key)->toStartWith('rc_')->toEndWith('.png');
    $this->withToken($token)->get('/api/v1/marketing/files/'.$key)->assertOk()->assertHeader('Content-Type', 'image/png');
});
