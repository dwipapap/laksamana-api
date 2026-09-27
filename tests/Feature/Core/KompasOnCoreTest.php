<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * #69: kompas served from core. Each test imports the restored kompas DB,
 * switches the Modul to core and exercises compat + v1 there; the default
 * (legacy) connection is the rollback path, covered by the rest of the suite.
 */

/** The legacy tables the module maps that are EMPTY in the restored dump. */
function kompasCoreSeed(): void
{
    $l = DB::connection('legacy_kompas');
    $l->table('void_setting')->insert(['id' => 1, 'tax_persen' => '10.000', 'service_persen' => '5.000',
        'updated_at' => 1790000000000, 'updated_by' => 'Seed Admin']);
    $l->table('void_log')->insert(['id' => 'vseed1', 'tgl' => '2026-09-10', 'bill' => 'B-1', 'item' => 'Es Teh',
        'penginput' => 'Kasir A', 'salah' => 'Kasir A', 'alasan' => 'salah ketik', 'nominal' => 11500, 'subtotal' => 10000,
        'service' => 500, 'tax' => 1000, 'oleh' => 'Seed', 'oleh_id' => 'u-novi', 'dibuat' => 1790000000000,
        'diubah' => 1790000000000, 'diubah_oleh' => 'Seed']);
    $l->table('bri_mutasi')->insert(['id' => 'bseed1', 'sidik' => '2026-09-10|15:46|300000|#0', 'tgl' => '2026-09-10',
        'jam' => '15:46', 'nominal' => 300000, 'ket' => 'QRIS A', 'oleh' => 'Seed', 'dibuat' => 1790000000000,
        'diubah' => 1790000000000]);
    $l->table('inv_lapor')->insert(['bulan' => '2026-08', 'jenis' => 'balance', 'kunci' => 'lp_seed.pdf',
        'nama' => 'Balance.pdf', 'ukuran' => 1234, 'at' => 1790000000003, 'oleh' => 'Seed Admin']);
    $l->table('an_peran')->insert(['kunci' => '#u-novi', 'peran' => 'manajemen']);
}

/** POST a legacy text/plain JSON body to the compat URL with kompas on core. */
function kpCorePost(array $body): TestResponse
{
    return test()->call('POST', '/kompas-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

/** One row of a core kompas table, by its legacy key. */
function kpCore(string $table, string|int $legacyId): ?object
{
    return DB::connection('core')->table($table)->where('legacy_id', (string) $legacyId)->first();
}

beforeEach(function () {
    kompasCoreSeed();
    // the account import fills core.user, which the '#<user id>' keys link to
    // (independent of which connection the account Modul itself is on)
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'kompas'])->assertSuccessful();
    config(['laksamana.modules.kompas.connection' => 'core']);
});

it('serves getAll from core with the imported blob and version', function () {
    $row = kpCore('kompas_app_state', 1);
    $res = $this->get('/kompas-api-mysql/api.php')->assertOk()->assertJsonPath('ok', true)
        ->assertJsonPath('ts', (int) $row->updated_at);
    expect(count($res->json('data.daily')))->toBeGreaterThan(0)
        ->and(count($res->json('data.reports')))->toBeGreaterThan(0)
        ->and(DB::connection('legacy_kompas')->table('app_state')->value('data'))->toBe($row->data);  // read, never rewritten
});

it('saveAll writes the blob on core, honouring the stored version', function () {
    $ts = (int) kpCore('kompas_app_state', 1)->updated_at;
    kpCorePost(['action' => 'saveAll', 'baseTs' => $ts - 1, 'data' => ['daily' => []]])
        ->assertOk()->assertJsonPath('konflik', true)->assertJsonPath('ts', $ts);

    $out = kpCorePost(['action' => 'saveAll', 'baseTs' => $ts,
        'data' => ['_savedBy' => 'Kasir Core', 'daily' => [['date' => '2026-09-10', 'food' => 1000]]]])
        ->assertOk()->assertJsonPath('data.saved', true);
    $row = kpCore('kompas_app_state', 1);
    expect((int) $row->version)->toBe(2)->and((int) $row->updated_at)->toBe($out->json('data.ts'))
        ->and($row->oleh)->toBe('Kasir Core')->and(json_decode($row->data, true)['daily'])->toHaveCount(1);
});

it('truncates an over-long author on strict core like non-strict production (#97)', function () {
    $ts = (int) kpCore('kompas_app_state', 1)->updated_at;
    kpCorePost(['action' => 'saveAll', 'baseTs' => $ts, 'data' => ['_savedBy' => str_repeat('O', 200), 'daily' => []]])
        ->assertOk()->assertJsonPath('data.saved', true);

    expect(kpCore('kompas_app_state', 1)->oleh)->toBe(str_repeat('O', 120));
});

it('legacy void writes, cancels and lists on core, never on legacy', function () {
    $sesi = legacySesi(officeUser('u-novi'));
    $id = kpCorePost(['action' => 'voidSimpan', 'sesi' => $sesi, 'data' => ['tgl' => '2026-09-12', 'bill' => 'B-9',
        'item' => 'Kopi', 'penginput' => 'A', 'salah' => 'B', 'alasan' => 'x', 'subtotal' => 10000, 'service' => 500,
        'tax' => 1000]])->assertOk()->assertJsonPath('data.baru', true)->json('data.id');
    $row = kpCore('kompas_void_log', $id);
    // `oleh_id` is whatever identity the caller was on; user_id resolves it (never NULL here)
    $uid = DB::connection('core')->table('user')->where('legacy_id', $row->oleh_id)->orWhere('id', $row->oleh_id)->value('id');
    expect((int) $row->nominal)->toBe(11500)->and($row->oleh)->toBe(officeUser('u-novi')['name'])
        ->and((int) $row->version)->toBe(1)->and($uid)->not->toBeNull()->and($row->user_id)->toBe($uid)
        ->and(DB::connection('legacy_kompas')->table('void_log')->where('id', $id)->exists())->toBeFalse();

    kpCorePost(['action' => 'voidBatal', 'sesi' => $sesi, 'id' => $id, 'alasan' => 'salah input'])
        ->assertOk()->assertJsonPath('data.saved', true);
    expect((int) kpCore('kompas_void_log', $id)->version)->toBe(2);

    $list = kpCorePost(['action' => 'voidList', 'dari' => '2026-09-01', 'sampai' => '2026-09-30'])->assertOk();
    // the percentage is a JSON number: a whole rate may come back as 10 rather than
    // 10.0 (the wire value is the same; parity compares numerically), so compare as float
    expect($list->json('data.total'))->toBe(2)->and((float) $list->json('data.setting.tax'))->toBe(10.0);
    // the wire keeps the LEGACY id, never the ULID (parity ignores these paths)
    $ids = array_column($list->json('data.baris'), 'id');
    expect($ids)->toContain('vseed1')->toContain($id)
        ->and(array_filter($ids, fn ($i) => strlen($i) === 26 && ! str_starts_with($i, 'v')))->toBe([]);
    // …and the actor name lands in `diubah_oleh` with a real `diubah` stamp, not in a
    // bigint (a shifted binding used to write the name into `diubah`)
    $baris = collect($list->json('data.baris'))->firstWhere('id', $id);
    expect((float) $baris['diubah'])->toBeGreaterThan(0)
        ->and($baris['diubahOleh'])->toBe(officeUser('u-novi')['name'])
        ->and((float) $baris['diubah'])->toBe((float) kpCore('kompas_void_log', $id)->diubah)
        ->and((float) $baris['dibuat'])->toBe((float) $baris['diubah']);

    $bri = kpCorePost(['action' => 'briUnggah', 'sesi' => $sesi, 'data' => ['baris' => [
        ['tgl' => '2026-09-13', 'jam' => '11:00', 'nominal' => 250000, 'ket' => 'QRIS C']]]])->assertOk();
    $briRows = kpCorePost(['action' => 'briList', 'dari' => '2026-09-01', 'sampai' => '2026-09-30'])
        ->assertOk()->json('data.baris');
    $briIds = array_column($briRows, 'id');
    expect($briIds)->toContain('bseed1')->and(array_filter($briIds, fn ($i) => strlen($i) === 26))->toBe([]);
    $briBaris = collect($briRows)->firstWhere('id', $briIds[1]);
    $briRow = kpCore('kompas_bri_mutasi', $briIds[1]);
    expect((float) $briBaris['diubah'])->toBeGreaterThan(0)
        ->and($briBaris['diubahOleh'])->toBe(officeUser('u-novi')['name'])
        ->and((float) $briBaris['nominal'])->toBe(250000.0)
        // user_id resolves the same actor key the row recorded
        ->and($briRow->user_id)->toBe(DB::connection('core')->table('user')
        ->where('legacy_id', $briRow->oleh_id)->orWhere('id', $briRow->oleh_id)->value('id'));
    // and that same legacy id is what the next compat write accepts
    kpCorePost(['action' => 'briBatal', 'sesi' => $sesi, 'id' => $briIds[1], 'alasan' => 'uji'])
        ->assertOk()->assertJsonPath('data.saved', true);
    expect((int) kpCore('kompas_bri_mutasi', $briIds[1])->batal_at)->toBeGreaterThan(0)
        ->and($bri->json('data.n'))->toBe(1);
});

it('v1 writes the targets and a void on core', function () {
    $token = loginAs(officeUser('u-novi'));
    $this->withToken($token)->putJson('/api/v1/kompas/targets', ['companyMonthlyTarget' => '1.000.000'])
        ->assertOk()->assertJsonPath('data.saved', true);
    expect(kpCore('kompas_app_state', 1)->oleh)->toBe(officeUser('u-novi')['name']);

    $id = $this->withToken($token)->postJson('/api/v1/kompas/voids', ['tgl' => '2026-09-12', 'bill' => 'B-9', 'item' => 'Kopi',
        'penginput' => 'A', 'salah' => 'B', 'alasan' => 'x', 'subtotal' => 1000])->assertCreated()->json('data.id');
    expect((int) kpCore('kompas_void_log', $id)->subtotal)->toBe(1000);
});

it('v1 lets a module admin write the void settings on core', function () {
    // one identity per test (the guard resolves it once, so a second loginAs is ignored)
    $admin = loginAs(officeUser('u-wandi'));
    $v = $this->withToken($admin)->getJson('/api/v1/kompas/voids/settings')->assertOk()->json('meta.version');
    $res = $this->withToken($admin)->putJson('/api/v1/kompas/voids/settings?version='.$v, ['tax' => 11, 'service' => 4])
        ->assertOk();
    expect((float) $res->json('data.setting.tax'))->toBe(11.0); // see the voidList note: compare numerically
    // the percentages live in kompas_pengaturan as the `void` document
    $doc = json_decode(DB::connection('core')->table('kompas_pengaturan')->where('k', 'void')->value('v'), true);
    expect((float) $doc['tax_persen'])->toBe(11.0)->and((float) $doc['service_persen'])->toBe(4.0)
        ->and($doc['oleh'])->toBe(officeUser('u-wandi')['name']);
});

it('keeps the cross-module agenda reading the other Moduls through their services', function () {
    // marketing + event + bd are read in-process (MarketingState, EventState,
    // BdState), never through kompas' own tables — on core that is unchanged.
    $res = kpCorePost(['action' => 'investorAgenda', 'sesi' => legacySesi(officeUser('u-dwipa'))])->assertOk();
    expect($res->json('data.gagal'))->toBe([])->and($res->json('data'))->toHaveKeys(['event', 'promo', 'hariIni']);
});
