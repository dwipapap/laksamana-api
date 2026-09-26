<?php

use Illuminate\Support\Facades\DB;

/*
 * #49: marketing served from core. Each test imports the restored marketing
 * DB and switches the Modul to core; the default (legacy) connection is the
 * rollback path, covered by the rest of the suite.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'marketing'])->assertSuccessful();
    config(['laksamana.modules.marketing.connection' => 'core']);
});

function coreClient(string $id): ?array
{
    $row = DB::connection('core')->table('marketing_klien')->where('legacy_id', $id)->first();

    return $row ? ['v' => (int) $row->updated_at, 'version' => (int) $row->version, 'data' => json_decode($row->data, true)] : null;
}

it('serves the legacy getAll from core with vip, designreqs and settings', function () {
    $res = $this->get('/marketing-api-mysql/api.php?action=getAll')->assertOk()->json();

    expect($res['ok'])->toBeTrue()
        ->and($res['data'])->toHaveKeys(['clients', 'events', 'followups', 'vip', 'designreqs', 'activities', 'settings', '_versi'])
        ->and($res['data']['clients'])->not->toBeEmpty()
        ->and($res['data']['vip'])->not->toBeEmpty()
        ->and($res['data']['designreqs'])->not->toBeEmpty()
        ->and($res['data']['_versi'])->toBeGreaterThan(0);
});

it('writes saveAll rows to the core tables with legacy ids on the wire', function () {
    $r = $this->legacyPost('/marketing-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'clients' => [['id' => 'c_core1', 'nama' => 'Core', 'updatedAt' => 1790100000000, 'createdAt' => 1790100000000]],
    ]])->assertOk()->json();

    expect($r['ok'])->toBeTrue()->and($r['data']['bentrok'])->toBe([]);
    $row = coreClient('c_core1');
    expect($row['data']['nama'])->toBe('Core')->and($row['v'])->toBe(1790100000000)->and($row['version'])->toBe(1);
});

it('keeps the stale-edit conflict guard on core', function () {
    $this->legacyPost('/marketing-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'clients' => [['id' => 'c_core2', 'nama' => 'A', 'updatedAt' => 1000, 'createdAt' => 1000]],
    ]]);
    $this->legacyPost('/marketing-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'clients' => [['id' => 'c_core2', 'nama' => 'B', 'updatedAt' => 2000, 'baseUpdatedAt' => 1000]],
    ]]);
    $r = $this->legacyPost('/marketing-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'clients' => [['id' => 'c_core2', 'nama' => 'STALE', 'updatedAt' => 3000, 'baseUpdatedAt' => 1000]],
    ]])->assertOk()->json();

    expect($r['data']['bentrok'])->toHaveCount(1)
        ->and($r['data']['bentrok'][0])->toMatchArray(['koleksi' => 'clients', 'id' => 'c_core2', 'versiServer' => 2000])
        ->and(coreClient('c_core2')['data']['nama'])->toBe('B');
});

it('merges vip per row into the core child table', function () {
    $before = DB::connection('core')->table('marketing_vip')->count();
    $this->legacyPost('/marketing-api-mysql/api.php', ['action' => 'saveAll', 'data' => [
        'vip' => [['id' => 'vip_core1', 'nama' => 'Core VIP', 'tanggal' => '2026-10-10', 'jenis' => 'Assisted', 'updatedAt' => 1790100001000]],
    ]])->assertOk();

    $row = DB::connection('core')->table('marketing_vip')->where('legacy_id', 'vip_core1')->first();
    expect(DB::connection('core')->table('marketing_vip')->count())->toBe($before + 1)
        ->and($row->tanggal)->toBe('2026-10-10')
        ->and($row->jenis)->toBe('Assisted');
});

it('answers eventsHari and dpMasuk from core', function () {
    $this->get('/marketing-api-mysql/api.php?action=eventsHari&tgl=2026-09-20')
        ->assertOk()->assertJsonPath('ok', true)
        ->assertJsonStructure(['data' => ['events', 'vip', 'settings']]);
    $this->get('/marketing-api-mysql/api.php?action=dpMasuk&dari=2026-09-01&sampai=2026-09-30')
        ->assertOk()->assertJsonPath('ok', true)
        ->assertJsonStructure(['data' => ['baris', 'total', 'vipTerkunci', 'luar']]);
});

it('serves designReqs and designReqSet from core', function () {
    $this->get('/marketing-api-mysql/api.php?action=designReqs')
        ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['reqs', 'opsi']]);
    $this->legacyPost('/marketing-api-mysql/api.php', ['action' => 'designReqSet', 'id' => 'dr_u7lzbj8', 'status' => 'done', 'picNama' => 'Core'])
        ->assertOk()->assertJsonPath('data.status', 'done');

    $prog = json_decode(DB::connection('core')->table('marketing_pengaturan')->where('k', 'extra:designreqprog')->value('v'), true);
    expect($prog['dr_u7lzbj8']['status'])->toBe('done');
});

it('keeps the legacy stats keys on core', function () {
    $res = $this->get('/marketing-api-mysql/api.php?action=stats')->assertOk()->json();

    expect($res['data'])->toHaveKeys(['clients', 'events', 'followups', 'activities'])
        ->and($res['data']['clients'])->toBe(DB::connection('core')->table('marketing_klien')->count());
});

it('runs the v1 record round-trip on core with legacy ids', function () {
    $token = loginAs(officeUser('u-aurel'));

    $created = $this->withToken($token)->postJson('/api/v1/marketing/clients', ['nama' => 'V1 Core'])
        ->assertCreated()->json('data');
    $id = $created['id'];
    $version = MarketingRecordsVersion($id);

    $this->withToken($token)->putJson("/api/v1/marketing/clients/$id", ['nama' => 'V1 Core 2', 'version' => $version])
        ->assertOk()->assertJsonPath('data.nama', 'V1 Core 2');

    expect(coreClient($id)['data']['nama'])->toBe('V1 Core 2');

    $this->withToken($token)->deleteJson("/api/v1/marketing/clients/$id", ['version' => MarketingRecordsVersion($id)])
        ->assertOk();
    expect(coreClient($id))->toBeNull();
});

function MarketingRecordsVersion(string $id): int
{
    return (int) (coreClient($id)['v'] ?? 0);
}
