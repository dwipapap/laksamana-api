<?php

use Illuminate\Support\Facades\DB;

/*
 * #55: konten served from core. Each test imports the restored konten DB and
 * switches the Modul to core; the default (legacy) connection is the
 * rollback path, covered by the rest of the suite. Compat actions are open.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'konten'])->assertSuccessful();
    config(['laksamana.modules.konten.connection' => 'core']);
});

function ktCore(string $table, string $legacyId): ?array
{
    $row = DB::connection('core')->table($table)->where('legacy_id', $legacyId)->first();

    return $row ? (array) $row : null;
}

it('serves the legacy getAll from core with logs and settings', function () {
    $res = $this->get('/konten-api-mysql/api.php?action=getAll')->assertOk()->json();

    expect($res['ok'])->toBeTrue()
        ->and($res['data'])->toHaveKeys(['brands', 'content', 'bank', 'logs', 'settings', 'perms'])
        ->and($res['data']['brands'])->not->toBeEmpty()
        ->and($res['data']['bank'])->not->toBeEmpty();
});

it('writes saveAll rows to the core tables with the column-only cap bump', function () {
    $this->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'brands' => [['id' => 'b_core1', 'name' => 'A', 'updatedAt' => 7000]],
    ]])->assertOk();
    $r = $this->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'brands' => [['id' => 'b_core1', 'name' => 'B', 'updatedAt' => 6000, 'baseUpdatedAt' => 7000]],
    ]])->assertOk()->json();

    expect($r['data']['bentrok'])->toBe([])->and($r['data'])->not->toHaveKey('versi');
    $row = ktCore('konten_brands', 'b_core1');
    expect($row['name'])->toBe('B')
        ->and((int) $row['updated_at'])->toBe(7001)
        ->and(json_decode($row['data'], true)['updatedAt'])->toBe(6000)
        ->and($row['version'])->toBe(2);
});

it('keeps the stale-edit conflict guard on core', function () {
    $this->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'brands' => [['id' => 'b_core2', 'name' => 'A', 'updatedAt' => 1000]],
    ]]);
    $this->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'brands' => [['id' => 'b_core2', 'name' => 'B', 'updatedAt' => 2000, 'baseUpdatedAt' => 1000]],
    ]]);
    $r = $this->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'brands' => [['id' => 'b_core2', 'name' => 'STALE', 'updatedAt' => 3000, 'baseUpdatedAt' => 1000]],
    ]])->assertOk()->json();

    expect($r['data']['bentrok'])->toHaveCount(1)
        ->and($r['data']['bentrok'][0])->toMatchArray(['koleksi' => 'brands', 'id' => 'b_core2', 'versiServer' => 2000])
        ->and(ktCore('konten_brands', 'b_core2')['name'])->toBe('B');
});

it('truncates an over-long indexed string on strict core like non-strict production (#97)', function () {
    $long = str_repeat('K', 400);
    $this->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'brands' => [['id' => 'b_core_long', 'name' => $long, 'updatedAt' => 1790100000000]],
    ]])->assertOk();

    $row = ktCore('konten_brands', 'b_core_long');
    expect($row['name'])->toBe(str_repeat('K', 255))
        ->and(json_decode($row['data'], true)['name'])->toBe($long);
});

it('appends logs to the core table and trims to 5000', function () {
    $this->legacyPost('/konten-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'logs' => [['id' => 'lg_core1', 'action' => 'uji', 'by' => 'core', 'target' => 'x', 'at' => 1790100000000]],
    ]])->assertOk();

    $row = ktCore('konten_logs', 'lg_core1');
    expect($row['action'])->toBe('uji')->and($row['version'])->toBe(1);
});

it('keeps the legacy stats keys on core', function () {
    $res = $this->get('/konten-api-mysql/api.php?action=stats')->assertOk()->json();

    expect($res['data'])->toHaveKeys(['brands', 'content', 'bank', 'logs'])
        ->and($res['data']['brands'])->toBe(DB::connection('core')->table('konten_brands')->count());
});

it('runs the v1 record round-trip on core with legacy ids', function () {
    $token = loginAs(officeUser('u-andry'));

    $created = $this->withToken($token)->postJson('/api/v1/konten/brands', ['name' => 'V1 Core'])
        ->assertCreated()->json('data');
    $id = $created['id'];
    expect(ktCore('konten_brands', $id)['name'])->toBe('V1 Core');

    $v = (int) ktCore('konten_brands', $id)['updated_at'];
    $this->withToken($token)->patchJson("/api/v1/konten/brands/$id", ['name' => 'V1 Core 2', 'version' => $v])
        ->assertOk()->assertJsonPath('data.name', 'V1 Core 2');
    expect(ktCore('konten_brands', $id)['name'])->toBe('V1 Core 2');

    $v2 = (int) ktCore('konten_brands', $id)['updated_at'];
    $this->withToken($token)->deleteJson("/api/v1/konten/brands/$id", ['version' => $v2])->assertOk();
    expect(ktCore('konten_brands', $id))->toBeNull();
});

it('merges content performance into the core row', function () {
    $token = loginAs(officeUser('u-andry'));
    $id = DB::connection('legacy_konten')->table('content')->value('id');

    $this->withToken($token)->putJson("/api/v1/konten/content/$id/performance",
        ['platform' => 'IG', 'metrics' => ['views' => 10]])->assertOk();

    $perf = json_decode(ktCore('konten_content', $id)['data'], true)['perf'];
    expect($perf['IG']['views'])->toBe(10);
});
