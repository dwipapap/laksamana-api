<?php

use App\Support\Modules;

/* u-novi holds module `finance` (not admin); u-wandi is superadmin; u-adit has no finance. */

it('requires login and the finance module', function () {
    $this->getJson('/api/v1/finance/petty-cash')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/finance/petty-cash')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('creates a transaction as the session user, then edits, marks and deletes it with versions', function () {
    $token = loginAs(officeUser('u-novi'));
    $res = $this->withToken($token)->postJson('/api/v1/finance/petty-cash/transactions',
        ['tgl' => '2026-09-04', 'keterangan' => 'Token listrik', 'oleh' => 'spoof', 'baris' => [['pos_id' => 1, 'kredit' => 50000]]])
        ->assertCreated()->assertJsonPath('data.dibuat_oleh', officeUser('u-novi')['name'])->assertJsonPath('data.baris.0.kredit', 50000);
    $id = $res->json('data.id');
    $v = $res->json('meta.version');

    $this->withToken($token)->postJson('/api/v1/finance/petty-cash/transactions', ['tgl' => 'bad', 'keterangan' => 'x'])
        ->assertStatus(422)->assertJsonPath('error.message', 'Tanggal tidak sah.');

    $this->withToken($token)->patchJson("/api/v1/finance/petty-cash/transactions/$id", ['bon' => true])->assertStatus(428);
    $this->withToken($token)->patchJson("/api/v1/finance/petty-cash/transactions/$id?version=stale", ['bon' => true])
        ->assertStatus(409)->assertJsonPath('error.details.current.id', $id);
    $v2 = $this->withToken($token)->patchJson("/api/v1/finance/petty-cash/transactions/$id?version=$v", ['bon' => true])
        ->assertOk()->assertJsonPath('data.bon', 1)->json('meta.version');

    $v3 = $this->withToken($token)->putJson("/api/v1/finance/petty-cash/transactions/$id?version=$v2",
        ['tgl' => '2026-09-05', 'keterangan' => 'Token listrik', 'bon' => 1, 'baris' => [['pos_id' => 2, 'kredit' => 40000], ['pos_id' => 3, 'kredit' => 10000]]])
        ->assertOk()->assertJsonCount(2, 'data.baris')->json('meta.version');

    $this->withToken($token)->deleteJson("/api/v1/finance/petty-cash/transactions/$id?version=$v3")->assertOk()->assertJsonPath('data.deleted', true);
    expect(Modules::db('finance')->selectOne('SELECT COUNT(*) n FROM kk_trx_pos WHERE trx_id = ?', [$id])->n)->toBe(0);
});

it('manages sources: create, rename/deactivate with version, refuse deleting a used one', function () {
    $token = loginAs(officeUser('u-novi'));
    $res = $this->withToken($token)->postJson('/api/v1/finance/petty-cash/sources', ['nama' => 'Pos API', 'urut' => 99])->assertCreated();
    $id = $res->json('data.id');
    $this->withToken($token)->patchJson("/api/v1/finance/petty-cash/sources/$id?version=".$res->json('meta.version'), ['aktif' => false, 'nama' => 'Pos API 2'])
        ->assertOk()->assertJsonPath('data.aktif', false)->assertJsonPath('data.nama', 'Pos API 2');

    $used = $this->withToken($token)->getJson('/api/v1/finance/petty-cash/sources')->json('meta.versions.1');
    $this->withToken($token)->deleteJson("/api/v1/finance/petty-cash/sources/1?version=$used")
        ->assertStatus(422)->assertJsonPath('error.message', 'Pos ini sudah dipakai transaksi — nonaktifkan saja.');
});

it('refuses Akses Halaman edits from a non-admin finance user', function () {
    $this->withToken(loginAs(officeUser('u-novi')))->putJson('/api/v1/finance/petty-cash/access/matrix?version=x', ['matrix' => []])
        ->assertStatus(403);
});

it('lets the module admin edit the Akses Halaman matrix and a role', function () {
    $token = loginAs(officeUser('u-wandi'));
    $v = $this->withToken($token)->getJson('/api/v1/finance/petty-cash/access')->assertOk()->json('meta.version');
    $this->withToken($token)->putJson("/api/v1/finance/petty-cash/access/matrix?version=$v", ['matrix' => ['viewer' => ['rekap' => 1]]])
        ->assertOk()->assertJsonPath('data.viewer.rekap', 1);
    expect(Modules::db('finance')->selectOne('SELECT COUNT(*) n FROM kk_akses')->n)->toBe(1);

    $this->withToken($token)->putJson('/api/v1/finance/petty-cash/access/roles/u-novi?version='.substr(sha1('null'), 0, 16), ['role' => 'manajemen'])
        ->assertOk()->assertJsonPath('data', 'manajemen');
});
