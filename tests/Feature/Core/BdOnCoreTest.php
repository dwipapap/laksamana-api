<?php

use Illuminate\Support\Facades\DB;

/*
 * #59: BD OS served from core. Each test imports the restored bd DB and switches
 * the Modul to core; the default (legacy) connection is the rollback path,
 * covered by the rest of the suite.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'bd'])->assertSuccessful();
    config(['laksamana.modules.bd.connection' => 'core']);
});

function bdCore(string $table, string $legacyId): ?object
{
    return DB::connection('core')->table($table)->where('legacy_id', $legacyId)->first();
}

it('serves getAll from core with the legacy ids and settings documents', function () {
    $d = $this->get('/bd-api-mysql/api.php')->assertOk()->json('data');
    expect(count($d['tasks']))->toBe(DB::connection('core')->table('bd_tasks')->count())
        ->and($d['tasks'][0]['id'])->toBe(DB::connection('core')->table('bd_tasks')->orderBy('created_at')->orderBy('legacy_id')->value('legacy_id'))
        ->and($d)->toHaveKeys(['focus', 'approverSets', 'promos', '_serverTs']);
});

it('saveAll keeps the updated_at guard and counts accepted writes in version', function () {
    $post = fn (array $tasks) => $this->legacyPost('/bd-api-mysql/api.php', ['action' => 'saveAll', 'sinceTs' => 0, 'data' => ['tasks' => $tasks]]);
    $keep = DB::connection('core')->table('bd_tasks')->pluck('data')->map(fn ($j) => json_decode($j, true))->all();
    $post([...$keep, ['id' => 't-core1', 'name' => 'A', 'updatedAt' => 7000, 'createdAt' => 7000]])->assertOk();
    $post([...$keep, ['id' => 't-core1', 'name' => 'B', 'updatedAt' => 6000, 'createdAt' => 7000]])->assertOk();  // older: ignored
    $post([...$keep, ['id' => 't-core1', 'name' => 'C', 'updatedAt' => 8000, 'createdAt' => 7000]])->assertOk();

    $row = bdCore('bd_tasks', 't-core1');
    expect($row->name)->toBe('C')->and((int) $row->updated_at)->toBe(8000)->and((int) $row->version)->toBe(2)
        ->and(DB::connection('legacy_bd')->table('tasks')->where('id', 't-core1')->exists())->toBeFalse();
});

it('Finance setRealisasi writes the PO on core and bumps its version', function () {
    $po = DB::connection('core')->table('bd_purchase_orders')->orderBy('legacy_id')->first();
    $this->legacyPost('/bd-api-mysql/api.php', ['action' => 'setRealisasi', 'id' => $po->legacy_id, 'realisasi' => '15000', 'oleh' => 'Novi'])
        ->assertOk()->assertJsonPath('data.realisasi', 15000);
    $row = bdCore('bd_purchase_orders', $po->legacy_id);
    expect($row->status)->toBe('Diterima')->and((int) $row->version)->toBe((int) $po->version + 1);
});

it('v1 records and settings documents work on core', function () {
    $token = loginAs(officeUser('u-novi'));
    $r = $this->withToken($token)->postJson('/api/v1/bd/tasks', ['name' => 'Core task'])->assertCreated();
    expect(bdCore('bd_tasks', $r->json('data.id'))->name)->toBe('Core task');
});
