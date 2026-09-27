<?php

use Illuminate\Support\Facades\DB;

/*
 * #57: akademi served from core. Each test imports the restored akademi DB
 * and switches the Modul to core; the default (legacy) connection is the
 * rollback path, covered by the rest of the suite. Compat actions are open.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'akademi'])->assertSuccessful();
    config(['laksamana.modules.akademi.connection' => 'core']);
});

function akCore(string $table, string $legacyId): ?array
{
    $row = DB::connection('core')->table($table)->where('legacy_id', $legacyId)->first();

    return $row ? (array) $row : null;
}

it('serves the legacy getAll from core with progress maps and activity', function () {
    $res = $this->get('/akademi-api-mysql/api.php?action=getAll')->assertOk()->json();

    expect($res['ok'])->toBeTrue()
        ->and($res['data'])->toHaveKeys(['users', 'divisions', 'materials', 'programs', 'progress', 'progProg', 'activity', 'settings'])
        ->and($res['data']['users'])->not->toBeEmpty();
});

it('writes saveAll rows to the core tables with the column-only cap bump', function () {
    $this->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'divisions' => [['id' => 'd_core1', 'name' => 'A', 'updatedAt' => 7000]],
    ]])->assertOk();
    $r = $this->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'divisions' => [['id' => 'd_core1', 'name' => 'B', 'updatedAt' => 6000, 'baseUpdatedAt' => 7000]],
    ]])->assertOk()->json();

    expect($r['data']['bentrok'])->toBe([])->and($r['data'])->not->toHaveKey('versi');
    $row = akCore('akademi_divisions', 'd_core1');
    expect($row['name'])->toBe('B')
        ->and((int) $row['updated_at'])->toBe(7001)
        ->and(json_decode($row['data'], true)['updatedAt'])->toBe(6000)
        ->and($row['version'])->toBe(2);
});

it('keeps the stale-edit conflict guard on core', function () {
    $this->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'divisions' => [['id' => 'd_core2', 'name' => 'A', 'updatedAt' => 1000]],
    ]]);
    $this->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'divisions' => [['id' => 'd_core2', 'name' => 'B', 'updatedAt' => 2000, 'baseUpdatedAt' => 1000]],
    ]]);
    $r = $this->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'divisions' => [['id' => 'd_core2', 'name' => 'STALE', 'updatedAt' => 3000, 'baseUpdatedAt' => 1000]],
    ]])->assertOk()->json();

    expect($r['data']['bentrok'])->toHaveCount(1)
        ->and($r['data']['bentrok'][0])->toMatchArray(['koleksi' => 'divisions', 'id' => 'd_core2', 'versiServer' => 2000])
        ->and(akCore('akademi_divisions', 'd_core2')['name'])->toBe('B');
});

it('truncates an over-long indexed string on strict core like non-strict production (#97)', function () {
    $long = str_repeat('A', 400);
    $this->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'users' => [['id' => 'u-core-long', 'name' => $long, 'updatedAt' => 1790100000000]],
    ]])->assertOk();

    $row = akCore('akademi_users', 'u-core-long');
    expect($row['name'])->toBe(str_repeat('A', 255))
        ->and(json_decode($row['data'], true)['name'])->toBe($long);
});

it('splits progress maps into composite-key core rows', function () {
    $this->legacyPost('/akademi-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'progress' => ['u-core1' => ['m-core1' => ['done' => true, 'score' => 90, 'at' => 1790100000000, 'updatedAt' => 1790100000000]]],
        'progProg' => ['u-core1' => ['p-core1' => ['m-core1' => ['done' => false, 'at' => 1790100000000, 'updatedAt' => 1790100000000]]]],
    ]])->assertOk();

    $p = akCore('akademi_progress', 'u-core1|m-core1');
    expect($p['done'])->toBe(1)->and($p['score'])->toBe(90)->and($p['version'])->toBe(1);
    $pp = akCore('akademi_prog_prog', 'u-core1|p-core1|m-core1');
    expect($pp['done'])->toBe(0)->and($pp['version'])->toBe(1);

    $res = $this->get('/akademi-api-mysql/api.php?action=getAll')->assertOk()->json();
    expect($res['data']['progress']['u-core1']['m-core1']['score'])->toBe(90)
        ->and($res['data']['progProg']['u-core1']['p-core1']['m-core1']['done'])->toBeFalse();
});

it('serves trainingStats from core', function () {
    $this->get('/akademi-api-mysql/api.php?action=trainingStats')
        ->assertOk()->assertJsonStructure(['data']);
});

it('keeps the legacy stats keys on core', function () {
    $res = $this->get('/akademi-api-mysql/api.php?action=stats')->assertOk()->json();

    expect($res['data'])->toHaveKeys(['users', 'materials', 'progress', 'prog_prog', 'activity'])
        ->and($res['data']['users'])->toBe(DB::connection('core')->table('akademi_users')->count());
});

it('runs the v1 record round-trip on core with legacy ids', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));

    $resp = $this->withToken($token)->postJson('/api/v1/akademi/divisions', ['name' => 'V1 Core'])
        ->assertCreated();
    $created = $resp->json('data');
    $id = $created['id'];
    expect(akCore('akademi_divisions', $id)['name'])->toBe('V1 Core');

    $v = $resp->json('meta.version');
    $this->withToken($token)->patchJson("/api/v1/akademi/divisions/$id", ['name' => 'V1 Core 2', 'version' => $v])
        ->assertOk()->assertJsonPath('data.name', 'V1 Core 2');
    expect(akCore('akademi_divisions', $id)['name'])->toBe('V1 Core 2');
});

it('writes progress cells to the composite-key core rows', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));

    $res = $this->withToken($token)->putJson('/api/v1/akademi/progress/u-core9/m-core9',
        ['done' => true, 'score' => 100])->assertOk();

    expect($res->json('data.done'))->toBeTrue()
        ->and($res->json('meta.version'))->toBeGreaterThan(0);
    $row = akCore('akademi_progress', 'u-core9|m-core9');
    expect($row['done'])->toBe(1)->and($row['score'])->toBe(100);
});
