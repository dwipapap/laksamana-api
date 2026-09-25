<?php

use App\Modules\Dw\Services\DwService;
use App\Support\Modules;

/*
 * /api/v1/dw — cluster (1): overview & schedule reads, workers, head
 * requests incl. assigning, assignments incl. decisions.
 *
 * ONE login per test: the Sanctum guard memoizes its user for the whole
 * test, so a second login would silently run as the first user.
 * Cross-identity setup goes through DwService directly.
 */

function dwSvc1(): DwService
{
    return app(DwService::class);
}

it('requires login', function () {
    $this->getJson('/api/v1/dw/overview?from=2026-09-01&to=2026-09-30')->assertStatus(401);
});

it('requires the dw module', function () {
    $this->withToken(loginAs(officeUser('u-yuzaalfarel')))->getJson('/api/v1/dw/overview?from=2026-09-01&to=2026-09-30')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('serves the overview with roles for HRD', function () {
    $r = $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->getJson('/api/v1/dw/overview?from=2026-09-01&to=2026-09-30')->assertOk()->json();
    expect($r['data'])->toHaveKeys(['setting', 'pekerja', 'ajuan', 'permintaan', 'peran'])
        ->and($r['data']['peran'])->toMatchArray(['hrd' => 1, 'admin' => 1])
        ->and(collect($r['data']['pekerja'])->firstWhere('id', 'DWmtxzw6fd369'))->toHaveKey('hp');
});

it('serves the overview with head roles', function () {
    $head = $this->withToken(loginAs(officeUser('u-arif')))
        ->getJson('/api/v1/dw/overview?from=2026-09-01&to=2026-09-30')->assertOk()->json();
    expect($head['data']['peran'])->toMatchArray(['hrd' => 0, 'head' => 1, 'divisi' => ['bar']]);
});

it('serves the schedule and validates its dates', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $this->withToken($token)->getJson('/api/v1/dw/schedule?from=2026-09-01&to=2026-09-30')->assertOk()
        ->assertJsonStructure(['data' => ['rows', 'dari', 'sampai']]);
    $this->withToken($token)->getJson('/api/v1/dw/schedule?from=2026-13-01&to=2026-09-30')->assertStatus(422);
});

it('refuses worker writes to non-HRD', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->postJson('/api/v1/dw/workers', ['nama' => 'X', 'hp' => '080000000031'])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('runs the worker lifecycle as HRD with filters', function () {
    $hrd = loginAs(officeUser('u-rizkiarfan'));

    $r = $this->withToken($hrd)->postJson('/api/v1/dw/workers', [
        'nama' => 'V Satu', 'hp' => '080000000031', 'divisi' => 'bar', 'posisi' => 'Bar Helper',
    ])->assertCreated()->json();
    $id = $r['data']['id'];
    expect($r['data'])->toMatchArray(['saved' => true, 'baru' => true]);

    $this->withToken($hrd)->getJson('/api/v1/dw/workers/'.$id)->assertOk()->assertJsonPath('data.nama', 'V Satu');
    $this->withToken($hrd)->getJson('/api/v1/dw/workers/DWtidakada')->assertStatus(404);
    expect($this->withToken($hrd)->getJson('/api/v1/dw/workers?q=v satu')->assertOk()->json('data'))->toHaveCount(1);
    expect($this->withToken($hrd)->getJson('/api/v1/dw/workers?divisi=kitchen')->assertOk()->json('data'))
        ->not->toContain($id);

    $this->withToken($hrd)->putJson('/api/v1/dw/workers/'.$id, ['nama' => 'V Satu', 'hp' => '080000000031', 'area' => 'Sleman'])
        ->assertOk()->assertJsonPath('data.baru', false);
    expect(Modules::db('dw')->selectOne('SELECT area FROM dw_pekerja WHERE id=?', [$id])->area)->toBe('Sleman');
    $this->withToken($hrd)->putJson('/api/v1/dw/workers/DWtidakada', ['nama' => 'X', 'hp' => '080000000032'])->assertStatus(404);

    $this->withToken($hrd)->deleteJson('/api/v1/dw/workers/'.$id)->assertOk()->assertJsonPath('data.deleted', true);
});

it('refuses worker deletes to non-HRD', function () {
    $id = dwSvc1()->savePekerja(['nama' => 'V Dua', 'hp' => '080000000033'], officeUser('u-rizkiarfan')['name'])['id'];
    $this->withToken(loginAs(officeUser('u-arif')))->deleteJson('/api/v1/dw/workers/'.$id)->assertStatus(403);
});

it('refuses head requests for other divisions', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->postJson('/api/v1/dw/requests', [
        'divisi' => 'kitchen', 'tgl' => '2031-01-05', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1,
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('runs the head request flow: create, read, cancel', function () {
    $bar = loginAs(officeUser('u-arif'));

    $id = $this->withToken($bar)->postJson('/api/v1/dw/requests', [
        'divisi' => 'bar', 'tgl' => '2031-01-05', 'm' => '18:00', 's' => '23:00', 'jumlah' => 2,
    ])->assertCreated()->json('data.row.id');
    expect($id)->toStartWith('PM');

    $this->withToken($bar)->getJson('/api/v1/dw/requests/'.$id)->assertOk()->assertJsonPath('data.divisi', 'bar');
    $this->withToken($bar)->getJson('/api/v1/dw/requests?divisi=bar&status=MENUNGGU')->assertOk()
        ->assertJsonFragment(['id' => $id]);

    // head approving their own request is refused; cancelling is allowed
    $this->withToken($bar)->postJson('/api/v1/dw/requests/'.$id.'/decision', ['status' => 'DISETUJUI'])->assertStatus(403);
    $this->withToken($bar)->postJson('/api/v1/dw/requests/'.$id.'/decision', ['status' => 'BATAL'])->assertOk()
        ->assertJsonPath('data.status', 'BATAL');
});

it('runs the HRD request flow: edit, approve, assign, delete', function () {
    $hrd = loginAs(officeUser('u-rizkiarfan'));
    $id = dwSvc1()->savePermintaan(['divisi' => 'bar', 'tgl' => '2031-01-06', 'm' => '18:00', 's' => '23:00', 'jumlah' => 2],
        officeUser('u-arif')['name'])['row']['id'];

    // HRD re-opens by editing, approves, assigns through v1
    $this->withToken($hrd)->putJson('/api/v1/dw/requests/'.$id, [
        'divisi' => 'bar', 'tgl' => '2031-01-06', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1,
    ])->assertOk()->assertJsonPath('data.row.status', 'MENUNGGU');
    $this->withToken($hrd)->postJson('/api/v1/dw/requests/'.$id.'/decision', ['status' => 'DISETUJUI'])->assertOk();
    $a = $this->withToken($hrd)->postJson('/api/v1/dw/requests/'.$id.'/assign', ['dwIds' => ['DWmtxzw6fd369']])->assertOk()->json();
    expect($a['data'])->toMatchArray(['masuk' => 1, 'tertahan' => [], 'terpenuhi' => 1, 'jumlah' => 1]);

    $this->withToken($hrd)->deleteJson('/api/v1/dw/requests/'.$id)->assertOk()->assertJsonPath('data.deleted', true);
});

it('runs the head assignment flow with overlap as 409', function () {
    $bar = loginAs(officeUser('u-arif'));

    $id = $this->withToken($bar)->postJson('/api/v1/dw/assignments', [
        'dwId' => 'DWmtxzw6fd369', 'tgl' => '2031-02-02', 'm' => '18:00', 's' => '23:00', 'divisi' => 'bar',
    ])->assertCreated()->json('data.row.id');

    // overlapping without timpa is a 409 with the conflict attached
    $this->withToken($bar)->postJson('/api/v1/dw/assignments', [
        'dwId' => 'DWmtxzw6fd369', 'tgl' => '2031-02-02', 'm' => '19:00', 's' => '21:00',
    ])->assertStatus(409)->assertJsonPath('error.code', 'overlap');
});

it('refuses assignment decisions to non-HRD', function () {
    $id = dwSvc1()->saveAjuan(['dwId' => 'DWmtxzw6fd369', 'tgl' => '2031-02-03', 'm' => '18:00', 's' => '23:00'],
        officeUser('u-arif')['name'])['row']['id'];
    $this->withToken(loginAs(officeUser('u-arif')))->postJson('/api/v1/dw/assignments/'.$id.'/decision', ['status' => 'DISETUJUI'])
        ->assertStatus(403);
});

it('runs the HRD assignment flow: decide, filter, bulk, delete', function () {
    $hrd = loginAs(officeUser('u-rizkiarfan'));
    $me = officeUser('u-arif')['name'];
    $id = dwSvc1()->saveAjuan(['dwId' => 'DWmtxzw6fd369', 'tgl' => '2031-02-04', 'm' => '18:00', 's' => '23:00'], $me)['row']['id'];
    $id2 = dwSvc1()->saveAjuan(['dwId' => 'DWmty9fok2781', 'tgl' => '2031-02-04', 'm' => '18:00', 's' => '23:00'], $me)['row']['id'];

    $this->withToken($hrd)->postJson('/api/v1/dw/assignments/'.$id.'/decision', ['status' => 'DISETUJUI', 'nota' => 'v1'])->assertOk();

    $this->withToken($hrd)->getJson('/api/v1/dw/assignments?dwId=DWmtxzw6fd369&from=2031-02-04&to=2031-02-04')->assertOk()
        ->assertJsonFragment(['id' => $id, 'status' => 'DISETUJUI']);

    $this->withToken($hrd)->postJson('/api/v1/dw/assignments/decisions', ['ids' => [$id, $id2], 'status' => 'DITOLAK'])
        ->assertOk()->assertJsonPath('data.jumlah', 2);

    $this->withToken($hrd)->deleteJson('/api/v1/dw/assignments/'.$id2)->assertOk();
    $this->withToken($hrd)->getJson('/api/v1/dw/assignments/'.$id2)->assertStatus(404);
});

it('refuses assignment deletes to non-HRD', function () {
    $id = dwSvc1()->saveAjuan(['dwId' => 'DWmtxzw6fd369', 'tgl' => '2031-02-05', 'm' => '18:00', 's' => '23:00'],
        officeUser('u-arif')['name'])['row']['id'];
    $this->withToken(loginAs(officeUser('u-arif')))->deleteJson('/api/v1/dw/assignments/'.$id)->assertStatus(403);
});
