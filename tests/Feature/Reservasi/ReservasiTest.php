<?php

use App\Support\Modules;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/helpers.php';

/* u-andry holds module reservasi; u-adit does not. */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

function rsPost(array $body): TestResponse
{
    return test()->call('POST', '/reservasi-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

function rsVer(): int
{
    return (int) Modules::db('reservasi')->selectOne(rsSql("SELECT v FROM settings WHERE k='_ver'"))->v;
}

it('getAll returns reservations, master, audit and the global _ver', function () {
    $d = $this->get('/reservasi-api-mysql/api.php')->assertOk()->json('data');
    expect(array_keys($d))->toBe(['reservations', 'master', 'audit', '_ver'])
        ->and($d['_ver'])->toBe(rsVer())
        ->and(count($d['reservations']))->toBe((int) Modules::db('reservasi')->selectOne(rsSql('SELECT COUNT(*) c FROM reservations'))->c);
});

it('saveAll: APP_LAWAS without baseVer, conflict on a stale one', function () {
    rsPost(['action' => 'saveAll', 'data' => ['reservations' => []]])->assertJsonPath('ok', false)
        ->assertJsonPath('error', fn ($e) => str_starts_with($e, 'APP_LAWAS'));
    rsPost(['action' => 'saveAll', 'baseVer' => 1, 'data' => ['reservations' => []]])
        ->assertJsonPath('ok', true)->assertJsonPath('data.conflict', true)->assertJsonPath('data.ver', rsVer());
});

it('saveAll moves inline photos to files and bumps _ver; the file is served back', function () {
    $ver = rsVer();
    $rows = Modules::db('reservasi')->select(rsSql('SELECT data FROM reservations'));
    $all = array_map(fn ($r) => json_decode($r->data, true), $rows);
    $all[0]['dpProofData'] = 'data:image/png;base64,QUJD';
    $id = $all[0]['id'];
    rsPost(['action' => 'saveAll', 'baseVer' => $ver, 'data' => ['reservations' => $all]])
        ->assertJsonPath('data.saved', true)->assertJsonPath('data.ver', $ver + 1)->assertJsonPath('data.fotoDipisah', 1);
    expect(json_decode(Modules::db('reservasi')->selectOne(rsSql('SELECT data FROM reservations WHERE id=?'), [$id])->data, true)['dpProofData'])->toBe("@f:r:$id:dp");
    $this->get('/reservasi-api-mysql/api.php?action=getFile&key='.urlencode("r:$id:dp"))->assertJsonPath('data.data', 'data:image/png;base64,QUJD');
});

it('v1: requires the module', function () {
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/reservasi/reservations')->assertStatus(403);
});

it('v1: create, patch with the row version, delete; each bumps _ver', function () {
    $token = loginAs(officeUser('u-andry'));
    $ver = rsVer();
    $res = $this->withToken($token)->postJson('/api/v1/reservasi/reservations', ['name' => 'Tamu API', 'date' => '2026-10-10', 'pax' => 4, 'dpProofData' => 'data:image/png;base64,WFla'])
        ->assertCreated();
    $id = $res->json('data.id');
    $v = $res->json('meta.version');
    expect($res->json('data.dpProofData'))->toBe("@f:r:$id:dp")->and(rsVer())->toBe($ver + 1)
        ->and(Modules::db('reservasi')->selectOne(rsSql('SELECT tanggal, pax FROM reservations WHERE id=?'), [$id]))->tanggal->toBe('2026-10-10')->pax->toBe(4);

    $this->withToken($token)->patchJson("/api/v1/reservasi/reservations/$id", ['pax' => 5])->assertStatus(428);
    $this->withToken($token)->patchJson("/api/v1/reservasi/reservations/$id?version=1", ['pax' => 5])->assertStatus(409);
    $v2 = $this->withToken($token)->patchJson("/api/v1/reservasi/reservations/$id?version=$v", ['pax' => 5])
        ->assertOk()->assertJsonPath('data.pax', 5)->assertJsonPath('data.name', 'Tamu API')->json('meta.version');
    $this->withToken($token)->deleteJson("/api/v1/reservasi/reservations/$id?version=$v2")->assertOk()->assertJsonPath('data.deleted', true);
    expect(rsVer())->toBe($ver + 3)->and(is_file(storage_path("framework/testing/reservasi-db/files/r_{$id}_dp.txt")))->toBeFalse();
});

it('v1: master is versioned by its content', function () {
    $token = loginAs(officeUser('u-andry'));
    $m = $this->withToken($token)->getJson('/api/v1/reservasi/master')->assertOk();
    $value = $m->json('data');
    $value['tables'][] = 'Meja API';
    $this->withToken($token)->putJson('/api/v1/reservasi/master?version=stale', ['value' => $value])->assertStatus(409);
    $this->withToken($token)->putJson('/api/v1/reservasi/master?version='.$m->json('meta.version'), ['value' => $value])
        ->assertOk()->assertJsonPath('data.tables', fn ($t) => in_array('Meja API', $t, true));
});
