<?php

use App\Support\Modules;

/*
 * Identities from the restored prod dump (jadwal_setting.heads):
 *   u-arif = head of Bar · u-yuzaalfarel = Bar crew · u-mella = head of Floor
 *   u-rizkiarfan = HRD (Tim "Office, HRD" => admin of jadwal)
 */

it('requires the jadwal module', function () {
    $this->getJson('/api/v1/jadwal/cells?from=2026-09-01&to=2026-09-30')->assertStatus(401);
});

it('lets a head write cells of their own division', function () {
    $token = loginAs(officeUser('u-arif'));
    $this->withToken($token)->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-yuzaalfarel', 'd' => '2026-10-05', 't' => 'PAGI', 'm' => '08:00', 's' => '16:00']],
    ])->assertOk()->assertJsonPath('data.isi', 1);

    $row = Modules::db('jadwal')->selectOne("SELECT shift, updated_by FROM jadwal_sel WHERE user_id='u-yuzaalfarel' AND tgl='2026-10-05'");
    expect($row->shift)->toBe('PAGI')
        ->and($row->updated_by)->toBe(officeUser('u-arif')['name']);
});

it('refuses a head writing another division', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-mella', 'd' => '2026-10-05', 't' => 'PAGI']],
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('refuses plain crew writing cells', function () {
    $this->withToken(loginAs(officeUser('u-yuzaalfarel')))->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-yuzaalfarel', 'd' => '2026-10-05', 't' => 'OFF']],
    ])->assertStatus(403);
});

it('forces crew requests onto the caller and to MENUNGGU', function () {
    $res = $this->withToken(loginAs(officeUser('u-yuzaalfarel')))->postJson('/api/v1/jadwal/requests', [
        'userId' => 'u-arif', 'jenis' => 'cuti', 'dari' => '2026-10-12', 'alasan' => 'test',
    ])->assertCreated();
    $row = Modules::db('jadwal')->selectOne('SELECT user_id, status, jenis FROM jadwal_pengajuan WHERE id = ?', [$res->json('data.id')]);
    expect($row->user_id)->toBe('u-yuzaalfarel')->and($row->status)->toBe('MENUNGGU')->and($row->jenis)->toBe('CUTI');
});

it('enforces head-then-HRD approval order', function () {
    Modules::db('jadwal')->insert("INSERT INTO jadwal_pengajuan (id,user_id,jenis,tgl_mulai,tgl_selesai,status) VALUES ('Atest1','u-yuzaalfarel','OFF','2026-10-12','2026-10-12','MENUNGGU')");

    // HRD may not take the head's step while the division has a head
    $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->postJson('/api/v1/jadwal/requests/Atest1/decision', ['status' => 'MENUNGGU_HRD'])
        ->assertStatus(422);
});

it('lets the head forward a request to HRD', function () {
    Modules::db('jadwal')->insert("INSERT INTO jadwal_pengajuan (id,user_id,jenis,tgl_mulai,tgl_selesai,status) VALUES ('Atest2','u-yuzaalfarel','OFF','2026-10-12','2026-10-12','MENUNGGU')");

    $this->withToken(loginAs(officeUser('u-arif')))
        ->postJson('/api/v1/jadwal/requests/Atest2/decision', ['status' => 'MENUNGGU_HRD', 'nota' => 'ok'])
        ->assertOk()->assertJsonPath('data.status', 'MENUNGGU_HRD');
    $row = Modules::db('jadwal')->selectOne("SELECT head_oleh FROM jadwal_pengajuan WHERE id='Atest2'");
    expect($row->head_oleh)->toBe(officeUser('u-arif')['name']);
});

it('lets HRD approve a forwarded request', function () {
    Modules::db('jadwal')->insert("INSERT INTO jadwal_pengajuan (id,user_id,jenis,tgl_mulai,tgl_selesai,status) VALUES ('Atest3','u-yuzaalfarel','OFF','2026-10-12','2026-10-12','MENUNGGU_HRD')");

    $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->postJson('/api/v1/jadwal/requests/Atest3/decision', ['status' => 'DISETUJUI'])
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
});

it('keeps settings writable by jadwal admins only', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->putJson('/api/v1/jadwal/settings', ['heads' => []])
        ->assertStatus(403);
});

it('stores empty setting maps as JSON objects', function () {
    $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->putJson('/api/v1/jadwal/settings', ['shifts' => ['PAGI' => ['m' => '08:00']], 'heads' => ['bar' => ['u-arif']], 'jabatan' => []])
        ->assertOk();
    $raw = Modules::db('jadwal')->selectOne('SELECT data FROM jadwal_setting WHERE id = 1')->data;
    expect($raw)->toContain('"jabatan":{}');
});

it('serves the open legacy shiftHari with filled default times', function () {
    $this->get('/jadwal-api-mysql/api.php?action=shiftHari&dari=2026-09-01&sampai=2026-09-30')
        ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['dari', 'sampai', 'rows']]);
});

it('rejects the legacy getAll without a session using the sesi_tidak_sah prefix', function () {
    $err = $this->get('/jadwal-api-mysql/api.php?action=getAll')->json('error');
    expect($err)->toStartWith('sesi_tidak_sah:');
});

it('accepts an old Office session token on the legacy route', function () {
    $sesi = legacySesi(officeUser('u-yuzaalfarel'));
    $this->get('/jadwal-api-mysql/api.php?action=getAll&dari=2026-09-01&sampai=2026-09-30&sesi='.$sesi)
        ->assertOk()->assertJsonPath('ok', true);
});
