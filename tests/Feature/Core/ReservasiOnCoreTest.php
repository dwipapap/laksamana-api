<?php

use App\Modules\Reservasi\Services\ReservasiState;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * #71: reservasi served from core. Where the comparison is meaningful the test
 * reads the legacy answer first, then imports the restored reservasi DB and
 * switches the Modul to core; the default (legacy) connection is the rollback
 * path, covered by tests/Feature/Reservasi.
 */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

function rsToCore(): void
{
    test()->artisan('core:import', ['module' => 'reservasi'])->assertSuccessful();
    config(['laksamana.modules.reservasi.connection' => 'core']);
    expect(ReservasiState::onCore())->toBeTrue();
}

function rsCorePost(array $body): TestResponse
{
    return test()->call('POST', '/reservasi-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

function rsOnCore(string $table, string $legacyId): ?object
{
    return DB::connection('core')->table($table)->where('legacy_id', $legacyId)->first();
}

it('serves the same getAll from core as from the legacy DB, with the legacy ids', function () {
    $legacy = $this->get('/reservasi-api-mysql/api.php')->assertOk()->json('data');
    rsToCore();
    $core = $this->get('/reservasi-api-mysql/api.php')->assertOk()->json('data');

    expect($core)->toEqual($legacy)
        ->and(count($core['reservations']))->toBe(DB::connection('core')->table('reservasi_reservations')->count())
        ->and($core['_ver'])->toBe((int) DB::connection('core')->table('reservasi_pengaturan')->where('k', '_ver')->value('v'))
        ->and($core['reservations'][0]['id'])->toBe($legacy['reservations'][0]['id']);
});

it('saveAll keeps the updated_at guard, the delete rule and the global _ver on core', function () {
    rsToCore();
    $core = DB::connection('core');
    $ver = (int) $core->table('reservasi_pengaturan')->where('k', '_ver')->value('v');
    $rows = $core->table('reservasi_reservations')->orderBy('legacy_id')->get()->map(fn ($r) => json_decode($r->data, true))->values();
    $gone = $rows->last()['id'];
    $keep = $rows->slice(0, -1)->values()->all();
    $new = ['id' => 'r-core1', 'name' => 'Baru', 'date' => '2026-10-20', 'pax' => 3, 'dpAmount' => 250000, 'updatedAt' => 1790000000000, 'createdAt' => 1790000000000];

    // an accepted write inserts a fresh row (ULID + legacy_id) and bumps _ver
    rsCorePost(['action' => 'saveAll', 'baseVer' => $ver, 'data' => ['reservations' => [...$keep, $new], 'audit' => []]])
        ->assertJsonPath('data.saved', true)->assertJsonPath('data.ver', $ver + 1);
    $row = rsOnCore('reservasi_reservations', 'r-core1');
    expect($row->name)->toBe('Baru')->and($row->tanggal)->toBe('2026-10-20')->and((int) $row->pax)->toBe(3)
        ->and((int) $row->dp_amount)->toBe(250000)->and((int) $row->updated_at)->toBe(1790000000000)
        ->and((int) $row->created_at)->toBe(1790000000000)->and((int) $row->version)->toBe(1)
        ->and(rsOnCore('reservasi_reservations', $gone))->toBeNull();   // delete-missing, by legacy_id

    // an older updatedAt is refused: the row keeps its values and its version
    rsCorePost(['action' => 'saveAll', 'baseVer' => $ver + 1, 'data' => ['reservations' => [...$keep, [...$new, 'name' => 'LAMA', 'updatedAt' => 1]], 'audit' => []]])
        ->assertJsonPath('data.saved', true);
    expect(rsOnCore('reservasi_reservations', 'r-core1')->name)->toBe('Baru')
        ->and((int) rsOnCore('reservasi_reservations', 'r-core1')->version)->toBe(1);

    // a newer one is accepted and counts: version + 1
    rsCorePost(['action' => 'saveAll', 'baseVer' => $ver + 2, 'data' => ['reservations' => [...$keep, [...$new, 'name' => 'Diubah', 'updatedAt' => 1790000001000]], 'audit' => []]])
        ->assertJsonPath('data.saved', true);
    expect(rsOnCore('reservasi_reservations', 'r-core1')->name)->toBe('Diubah')
        ->and((int) rsOnCore('reservasi_reservations', 'r-core1')->updated_at)->toBe(1790000001000)
        ->and((int) rsOnCore('reservasi_reservations', 'r-core1')->version)->toBe(2);

    // a stale baseVer still conflicts and writes nothing
    rsCorePost(['action' => 'saveAll', 'baseVer' => 1, 'data' => ['reservations' => []]])
        ->assertJsonPath('data.conflict', true)->assertJsonPath('data.saved', false);
    expect((int) $core->table('reservasi_pengaturan')->where('k', '_ver')->value('v'))->toBe($ver + 3)
        ->and((int) rsOnCore('reservasi_reservations', 'r-core1')->version)->toBe(2);
});

it('appends audit on core and trims it to the newest 500', function () {
    rsToCore();
    $core = DB::connection('core');
    $ver = (int) $core->table('reservasi_pengaturan')->where('k', '_ver')->value('v');
    $before = $core->table('reservasi_audit')->count();
    $ts = (int) $core->table('reservasi_audit')->max('ts') + 1000;   // the newest entry, so the trim keeps it

    rsCorePost(['action' => 'saveAll', 'baseVer' => $ver, 'data' => ['reservations' => [],
        'audit' => [['id' => 'a-core1', 'ts' => $ts, 'msg' => 'core']]]])->assertJsonPath('data.saved', true);

    $row = rsOnCore('reservasi_audit', 'a-core1');
    expect((int) $row->ts)->toBe($ts)->and((int) $row->version)->toBe(1)
        ->and($core->table('reservasi_audit')->count())->toBe($before >= 500 ? 500 : $before + 1)
        ->and(json_decode($row->data, true))->toMatchArray(['id' => 'a-core1', 'msg' => 'core']);
});

it('serves v1 writes from core under the row version and the global _ver', function () {
    rsToCore();
    $token = loginAs(officeUser('u-andry'));
    $ver = (int) DB::connection('core')->table('reservasi_pengaturan')->where('k', '_ver')->value('v');

    $created = $this->withToken($token)->postJson('/api/v1/reservasi/reservations', ['name' => 'Tamu Core', 'date' => '2026-10-25', 'pax' => 4])
        ->assertCreated();
    $id = $created->json('data.id');
    expect(rsOnCore('reservasi_reservations', $id)->name)->toBe('Tamu Core')
        ->and(rsOnCore('reservasi_reservations', $id)->tanggal)->toBe('2026-10-25')
        ->and((int) $created->json('meta.version'))->toBe((int) rsOnCore('reservasi_reservations', $id)->updated_at)
        ->and((int) DB::connection('core')->table('reservasi_pengaturan')->where('k', '_ver')->value('v'))->toBe($ver + 1);

    $v = $created->json('meta.version');
    $this->withToken($token)->patchJson("/api/v1/reservasi/reservations/$id?version=$v", ['pax' => 5])
        ->assertOk()->assertJsonPath('data.pax', 5);
    expect((int) rsOnCore('reservasi_reservations', $id)->pax)->toBe(5)
        ->and((int) rsOnCore('reservasi_reservations', $id)->version)->toBe(2);

    // the compat surface serves the same row under its legacy id
    $d = collect($this->get('/reservasi-api-mysql/api.php')->assertOk()->json('data.reservations'));
    expect($d->firstWhere('id', $id)['pax'])->toBe(5)
        ->and(DB::connection('legacy_reservasi')->table('reservations')->where('id', $id)->exists())->toBeFalse();
});

it('keeps the cross-module entry point working: Finance asks for a kwitansi per reservation id', function () {
    rsToCore();
    $token = loginAs(officeUser('u-andry'));
    $id = $this->withToken($token)->postJson('/api/v1/reservasi/reservations', ['name' => 'Tamu Kwitansi', 'date' => '2026-10-26'])
        ->assertCreated()->json('data.id');

    // Finance is the other Modul that calls Reservasi (one kwitansi per
    // reservation): the id on that boundary is the legacy id core now stores.
    $this->withToken($token)->postJson('/api/v1/finance/invoices/requests', ['resId' => $id, 'ringkas' => ['nama' => 'Tamu Kwitansi']])
        ->assertOk()->assertJsonPath('data.resId', $id);
    expect(DB::connection('legacy_finance')->table('inv_kwitansi')->where('res_id', $id)->value('status'))->toBe('MENUNGGU');
});
