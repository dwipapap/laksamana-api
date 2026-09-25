<?php

use App\Support\Modules;

/* u-andry holds module reservasi and has role "viewer" in master.users. */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

afterEach(function () {
    foreach (['rv_rvtest-review.txt', 'rv2_rvtest-review.txt', 'fb_fbtest-feedback.txt', 'vtest.txt'] as $name) {
        @unlink(storage_path('framework/testing/reservasi-db/files/'.$name));
    }
});

function rsScreensVer(): int
{
    return (int) Modules::db('reservasi')->selectOne("SELECT v FROM settings WHERE k='_ver'")->v;
}

it('v1: master sections keep their own content versions while every write bumps _ver', function () {
    $token = loginAs(officeUser('u-andry'));
    $tables = $this->withToken($token)->getJson('/api/v1/reservasi/master/tables')->assertOk();
    $reviews = $this->withToken($token)->getJson('/api/v1/reservasi/master/reviews')->assertOk();
    $dpMethods = $this->withToken($token)->getJson('/api/v1/reservasi/master/dpMethods')->assertOk()->json('data');
    $tablesVersion = $tables->json('meta.version');
    $value = [...$tables->json('data'), 'Meja API'];
    $ver = rsScreensVer();

    $this->withToken($token)->putJson('/api/v1/reservasi/master/tables', ['value' => $value])->assertStatus(428);
    $this->withToken($token)->putJson('/api/v1/reservasi/master/tables?version=stale', ['value' => $value])
        ->assertStatus(409)->assertJsonPath('error.details.current', $tables->json('data'));

    $saved = $this->withToken($token)->putJson('/api/v1/reservasi/master/tables?version='.$tablesVersion, ['value' => $value])
        ->assertOk()->assertJsonPath('data', $value);
    expect($saved->json('meta.version'))->not->toBe($tablesVersion);

    // The reviews version read BEFORE the tables write is still current: the two
    // screens never contend over each other's section.
    $this->withToken($token)->putJson('/api/v1/reservasi/master/reviews?version='.$reviews->json('meta.version'), ['value' => $reviews->json('data')])
        ->assertOk();

    expect($this->withToken($token)->getJson('/api/v1/reservasi/master/dpMethods')->json('data'))->toBe($dpMethods)
        ->and(rsScreensVer())->toBe($ver + 2);
});

it('v1: replaces one review with both proofs on disk, then deletes it and its files', function () {
    $token = loginAs(officeUser('u-andry'));
    $section = $this->withToken($token)->getJson('/api/v1/reservasi/master/reviews')->assertOk();
    $seeded = [...$section->json('data'), ['id' => 'rvtest-review', 'name' => 'Draft']];
    $section = $this->withToken($token)->putJson('/api/v1/reservasi/master/reviews?version='.$section->json('meta.version'), ['value' => $seeded])
        ->assertOk();
    $ver = rsScreensVer();

    $this->withToken($token)->putJson('/api/v1/reservasi/master/reviews/rvtest-review', [
        'value' => ['id' => 'rvtest-review', 'status' => 'diklaim', 'proofData' => 'data:image/png;base64,QUJD', 'proof2Data' => 'data:image/jpeg;base64,REVG'],
    ])->assertStatus(428);

    $item = $this->withToken($token)->putJson('/api/v1/reservasi/master/reviews/rvtest-review?version='.$section->json('meta.version'), [
        'value' => ['id' => 'rvtest-review', 'status' => 'diklaim', 'proofData' => 'data:image/png;base64,QUJD', 'proof2Data' => 'data:image/jpeg;base64,REVG'],
    ])->assertOk()->assertJsonPath('data.status', 'diklaim');

    expect($item->json('data.proofData'))->toBe('@f:rv:rvtest-review')
        ->and($item->json('data.proof2Data'))->toBe('@f:rv2:rvtest-review')
        ->and(rsScreensVer())->toBe($ver + 1);
    $this->withToken($token)->getJson('/api/v1/reservasi/files/'.rawurlencode('rv:rvtest-review'))->assertJsonPath('data.data', 'data:image/png;base64,QUJD');
    $this->withToken($token)->getJson('/api/v1/reservasi/files/'.rawurlencode('rv2:rvtest-review'))->assertJsonPath('data.data', 'data:image/jpeg;base64,REVG');

    $this->withToken($token)->putJson('/api/v1/reservasi/master/reviews/rvtest-review?version='.$item->json('meta.version'), [
        'value' => ['id' => 'other-id'],
    ])->assertStatus(422);

    $this->withToken($token)->deleteJson('/api/v1/reservasi/master/reviews/rvtest-review?version='.$item->json('meta.version'))
        ->assertOk()->assertJsonPath('data.deleted', true)->assertJsonStructure(['meta' => ['version']]);
    expect(Modules::db('reservasi')->selectOne("SELECT v FROM settings WHERE k='master'")->v)
        ->not->toContain('rvtest-review')
        ->and(is_file(storage_path('framework/testing/reservasi-db/files/rv_rvtest-review.txt')))->toBeFalse()
        ->and(is_file(storage_path('framework/testing/reservasi-db/files/rv2_rvtest-review.txt')))->toBeFalse()
        ->and(rsScreensVer())->toBe($ver + 2);
});

it('v1: replaces and deletes one feedback and one waitlist item by id', function () {
    $token = loginAs(officeUser('u-andry'));

    $feedbacks = $this->withToken($token)->getJson('/api/v1/reservasi/master/feedbacks')->assertOk();
    $feedbacks = $this->withToken($token)->putJson('/api/v1/reservasi/master/feedbacks?version='.$feedbacks->json('meta.version'), [
        'value' => [...$feedbacks->json('data'), ['id' => 'fbtest-feedback', 'status' => 'baru']],
    ])->assertOk();
    $this->withToken($token)->putJson('/api/v1/reservasi/master/feedbacks/fbtest-feedback?version='.$feedbacks->json('meta.version'), [
        'value' => ['id' => 'fbtest-feedback', 'status' => 'selesai', 'proofData' => 'data:image/png;base64,ZmVlZA=='],
    ])->assertOk()->assertJsonPath('data.proofData', '@f:fb:fbtest-feedback');
    $feedbacks = $this->withToken($token)->getJson('/api/v1/reservasi/master/feedbacks')->assertOk();
    $this->withToken($token)->deleteJson('/api/v1/reservasi/master/feedbacks/fbtest-feedback?version='.$feedbacks->json('meta.version'))->assertOk();
    expect(is_file(storage_path('framework/testing/reservasi-db/files/fb_fbtest-feedback.txt')))->toBeFalse();

    $waitlist = $this->withToken($token)->getJson('/api/v1/reservasi/master/waitlist')->assertOk();
    $waitlist = $this->withToken($token)->putJson('/api/v1/reservasi/master/waitlist?version='.$waitlist->json('meta.version'), [
        'value' => [...$waitlist->json('data'), ['id' => 'wtest-wait', 'status' => 'waiting']],
    ])->assertOk();
    $this->withToken($token)->putJson('/api/v1/reservasi/master/waitlist/wtest-wait?version='.$waitlist->json('meta.version'), [
        'value' => ['id' => 'wtest-wait', 'status' => 'cancelled'],
    ])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $waitlist = $this->withToken($token)->getJson('/api/v1/reservasi/master/waitlist')->assertOk();
    $this->withToken($token)->deleteJson('/api/v1/reservasi/master/waitlist/wtest-wait?version='.$waitlist->json('meta.version'))
        ->assertOk()->assertJsonPath('data.deleted', true);
});

it('v1: reservation writes audit the acting user, their master role and the capped row log', function () {
    $token = loginAs(officeUser('u-andry'));
    $ver = rsScreensVer();
    $created = $this->withToken($token)->postJson('/api/v1/reservasi/reservations', [
        'name' => 'Tamu Audit',
        'date' => '2026-10-20',
        'user' => ' spoofed',
        'role' => 'admin',
        '_audit' => ['action' => 'Buat Reservasi', 'detail' => str_repeat('d', 200)],
    ])->assertCreated();
    $id = $created->json('data.id');

    expect($created->json('data'))->not->toHaveKey('_audit')
        ->and($created->json('data.log.0'))->toMatchArray(['by' => 'Andry', 'role' => 'viewer', 'action' => 'Buat Reservasi'])
        ->and(mb_strlen($created->json('data.log.0.detail')))->toBe(180);
    $row = Modules::db('reservasi')->selectOne("SELECT data FROM audit WHERE JSON_UNQUOTE(JSON_EXTRACT(data,'$.res'))=?", [$id]);
    expect(json_decode($row->data, true))->toMatchArray(['user' => 'Andry', 'role' => 'viewer', 'action' => 'Buat Reservasi', 'res' => $id])
        ->and(json_decode($row->data, true)['detail'])->toHaveLength(200);

    $fullLog = array_map(fn ($i) => ['ts' => $i, 'by' => 'x', 'role' => 'viewer', 'action' => 'lama', 'detail' => ''], range(1, 25));
    $v = $created->json('meta.version');
    $patched = $this->withToken($token)->patchJson("/api/v1/reservasi/reservations/$id?version=$v", [
        'log' => $fullLog,
        '_audit' => ['action' => 'Edit Reservasi', 'detail' => 'ubah'],
    ])->assertOk();
    expect($patched->json('data.log'))->toHaveCount(20)
        ->and($patched->json('data.log.0'))->toMatchArray(['by' => 'Andry', 'role' => 'viewer', 'action' => 'Edit Reservasi', 'detail' => 'ubah'])
        ->and($patched->json('data.log.19.action'))->toBe('lama')
        ->and(rsScreensVer())->toBe($ver + 2);

    $audit = $this->withToken($token)->getJson('/api/v1/reservasi/audit')->assertOk()->json('data');
    expect($audit[0])->toMatchArray(['user' => 'Andry', 'role' => 'viewer', 'action' => 'Edit Reservasi', 'res' => $id])
        ->and($audit[1]['action'])->toBe('Buat Reservasi');
});

it('v1: POST /audit records an action that belongs to no reservation', function () {
    $token = loginAs(officeUser('u-andry'));
    $ver = rsScreensVer();

    $this->withToken($token)->postJson('/api/v1/reservasi/audit', ['detail' => 'no action'])->assertStatus(422);
    expect(rsScreensVer())->toBe($ver);

    $row = $this->withToken($token)->postJson('/api/v1/reservasi/audit', ['action' => 'Uji Audit', 'detail' => 'bukan milik reservasi'])
        ->assertCreated()->assertJsonPath('data.action', 'Uji Audit');
    expect($row->json('data'))->toMatchArray(['user' => 'Andry', 'role' => 'viewer', 'action' => 'Uji Audit', 'detail' => 'bukan milik reservasi'])
        ->and($row->json('data'))->not->toHaveKey('res')
        ->and($row->json('meta.ver'))->toBe($ver + 1);
    expect($this->withToken($token)->getJson('/api/v1/reservasi/audit')->json('data.0.id'))->toBe($row->json('data.id'));
});

it('v1: a file write bumps _ver and an empty write deletes the file', function () {
    $token = loginAs(officeUser('u-andry'));
    $ver = rsScreensVer();

    $this->withToken($token)->putJson('/api/v1/reservasi/files/vtest', ['data' => 'data:image/png;base64,QUJD'])
        ->assertOk()->assertJsonPath('data.len', 26);
    expect(is_file(storage_path('framework/testing/reservasi-db/files/vtest.txt')))->toBeTrue()
        ->and(rsScreensVer())->toBe($ver + 1);

    $this->withToken($token)->putJson('/api/v1/reservasi/files/vtest', ['data' => ''])->assertOk()->assertJsonPath('data.deleted', true);
    expect(is_file(storage_path('framework/testing/reservasi-db/files/vtest.txt')))->toBeFalse()
        ->and(rsScreensVer())->toBe($ver + 2);
});
