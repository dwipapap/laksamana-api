<?php

use Illuminate\Support\Facades\DB;

/*
 * #65: Howandi Life OS served from core. Each test imports the restored
 * hlife DB and switches the Modul to core; the default (legacy) connection
 * is the rollback path, covered by the rest of the suite.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'hlife'])->assertSuccessful();
    config(['laksamana.modules.hlife.connection' => 'core']);
});

function hlCore(string $table, string $legacyId): ?object
{
    $key = $table === 'hlife_pengaturan' ? 'k' : 'legacy_id';

    return DB::connection('core')->table($table)->where($key, $legacyId)->first();
}

it('serves getAll from core with the legacy ids and settings documents', function () {
    $d = $this->get('/howandi-life-api-mysql/api.php?action=getAll&token=HL-5mHh8Lfu8bpiPMkgtRphSmvM')->assertOk()->json('data');
    expect(count($d['tasks']))->toBe(DB::connection('core')->table('hlife_tasks')->count())
        ->and($d['tasks'][0]['id'])->toBe(DB::connection('core')->table('hlife_tasks')->value('legacy_id'))
        ->and($d)->toHaveKeys(['firstRun', 'mood', 'auth', 'channels', 'finance']);
});

it('saveAll replaces the state on core and counts every write in version', function () {
    $post = fn (mixed $data) => $this->legacyPost('/howandi-life-api-mysql/api.php',
        ['action' => 'saveAll', 'token' => 'HL-5mHh8Lfu8bpiPMkgtRphSmvM', 'data' => $data]);

    $post(['tasks' => [['id' => 't-core1', 'name' => 'A', 'done' => true, 'meta' => []]]])->assertOk();
    $row = hlCore('hlife_tasks', 't-core1');
    expect($row->nama)->toBe('A')->and(json_decode($row->data, true)['name'])->toBe('A')
        ->and((int) $row->version)->toBe(1)->and($row->legacy_id)->toBe('t-core1')
        ->and($row->id)->not->toBe('t-core1') // a fresh ULID is minted per row
        ->and(DB::connection('legacy_hlife')->table('tasks')->where('id', 't-core1')->exists())->toBeFalse();

    $post(['tasks' => [['id' => 't-core1', 'name' => 'B', 'done' => false]]])->assertOk();
    $row = hlCore('hlife_tasks', 't-core1');
    expect($row->nama)->toBe('B')->and((int) $row->version)->toBe(2)
        ->and((int) $row->updated_at)->toBeGreaterThanOrEqual((int) $row->created_at);

    // a save that empties everything (last save wins, as legacy)
    $post(['tasks' => []])->assertOk();
    expect(DB::connection('core')->table('hlife_tasks')->count())->toBe(0)
        ->and(DB::connection('legacy_hlife')->table('tasks')->count())->toBeGreaterThan(0);
});

it('keeps the strict rejection of a bad numeric column, like the parity case', function () {
    $this->legacyPost('/howandi-life-api-mysql/api.php', ['action' => 'saveAll',
        'token' => 'HL-5mHh8Lfu8bpiPMkgtRphSmvM',
        'data' => ['tasks' => [], 'finance' => ['ledger' => [['id' => 'lx', 'month' => '2026-01', 'income' => 1]]]]])
        ->assertStatus(500)->assertJsonPath('error', 'kesalahan server');
    expect(DB::connection('core')->table('hlife_ledger')->where('legacy_id', 'lx')->exists())->toBeFalse();
});

it('v1 records and settings work on core, and the version hash matches across storages', function () {
    $token = loginAs(officeUser('u-jb'));
    $r = $this->withToken($token)->postJson('/api/v1/hlife/tasks', ['name' => 'Core task'])->assertCreated();
    $id = $r->json('data.id');
    expect(hlCore('hlife_tasks', $id)->nama)->toBe('Core task');

    $v1 = $r->json('meta.version');
    $stored = DB::connection('core')->table('hlife_tasks')->where('legacy_id', $id)->value('data');
    expect($v1)->toBe(substr(sha1((string) $stored), 0, 16));

    $this->withToken($token)->withHeader('If-Match', "\"$v1\"")->patchJson("/api/v1/hlife/tasks/$id", ['done' => true])
        ->assertOk()->assertJsonPath('data.done', true);
    expect((int) hlCore('hlife_tasks', $id)->done)->toBe(1)
        ->and((int) hlCore('hlife_tasks', $id)->version)->toBe(2);

    $v = $this->withToken($token)->getJson('/api/v1/hlife/settings/mood')->assertOk()->json('meta.version');
    $this->withToken($token)->withHeader('If-Match', $v)->putJson('/api/v1/hlife/settings/mood', ['value' => 5])->assertOk();
    expect(hlCore('hlife_pengaturan', 'mood')->v)->toBe('5')
        ->and((int) hlCore('hlife_pengaturan', 'mood')->version)->toBe(2);
});
