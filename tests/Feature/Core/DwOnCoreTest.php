<?php

use App\Modules\Dw\Services\DwService;
use Illuminate\Support\Facades\DB;

/*
 * #51: dw served from core. Each test imports the restored dw DB and switches
 * the Modul to core; the default (legacy) connection is the rollback path,
 * covered by the rest of the suite.
 *
 * One auth identity per test (the Sesi guard caches its user); cross-identity
 * setup goes through DwService directly.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'dw'])->assertSuccessful();
    config(['laksamana.modules.dw.connection' => 'core']);
});

function dwCore(string $table, string $legacyId): ?array
{
    $row = DB::connection('core')->table($table)->where('legacy_id', $legacyId)->first();

    return $row ? (array) $row : null;
}

it('serves the open jadwalDW from core without phone numbers', function () {
    $r = $this->get('/dw-api-mysql/api.php?action=jadwalDW&dari=2026-09-01&sampai=2026-09-30')->assertOk()->json();

    expect($r['ok'])->toBeTrue()->and($r['data'])->toHaveKeys(['rows', 'dari', 'sampai']);
    expect($r['data']['rows'])->not->toBeEmpty();
    foreach ($r['data']['rows'] as $row) {
        expect($row)->toHaveKeys(['id', 'dwId', 'nama', 'divisi', 'posisi', 'tgl', 'm', 's', 'hadir'])
            ->and($row)->not->toHaveKeys(['hp', 'no_hp', 'pin', 'bayarNomor']);
    }
});

it('serves getAll from core with the expiry sweep and roles', function () {
    $sesi = legacySesi(officeUser('u-rizkiarfan'));
    $res = $this->get("/dw-api-mysql/api.php?action=getAll&dari=2026-09-01&sampai=2026-09-30&sesi=$sesi")
        ->assertOk()->json();

    expect($res['ok'])->toBeTrue()
        ->and($res['data'])->toHaveKeys(['setting', 'pekerja', 'ajuan', 'permintaan', 'peran'])
        ->and($res['data']['peran']['hrd'])->toBe(1)
        ->and($res['data']['pekerja'])->not->toBeEmpty();
});

it('saves a worker to core and refuses a duplicate phone', function () {
    $sesi = legacySesi(officeUser('u-rizkiarfan'));
    $r = $this->legacyPost('/dw-api-mysql/api.php', ['action' => 'simpanPekerja', 'sesi' => $sesi,
        'row' => ['nama' => 'Core Coba', 'hp' => '080000000042', 'divisi' => 'bar']])->assertOk()->json();

    expect($r['ok'])->toBeTrue()->and($r['data']['baru'])->toBeTrue();
    $row = dwCore('dw_pekerja', $r['data']['id']);
    expect($row['nama'])->toBe('Core Coba')->and($row['no_hp'])->toBe('080000000042')->and($row['version'])->toBe(1);

    $dup = $this->legacyPost('/dw-api-mysql/api.php', ['action' => 'simpanPekerja', 'sesi' => $sesi,
        'row' => ['nama' => 'Kembar', 'hp' => '080000000042']])->assertOk()->json();
    expect($dup['ok'])->toBeFalse()->and($dup['error'])->toContain('sudah terdaftar');
});

it('runs the request-assign-decide loop on core with legacy ids', function () {
    $svc = app(DwService::class);
    $by = officeUser('u-rizkiarfan')['name'];

    $pm = $svc->savePermintaan(['divisi' => 'bar', 'tgl' => '2031-09-01', 'm' => '18:00', 's' => '23:00', 'jumlah' => 1], $by);
    expect($pm['saved'])->toBeTrue();
    $pmId = $pm['row']['id'];
    expect(dwCore('dw_permintaan', $pmId)['status'])->toBe('MENUNGGU');

    $dw = DB::connection('legacy_dw')->table('dw_pekerja')->where('status', 'AKTIF')->value('id');
    $as = $svc->assignDw($pmId, [$dw], $by);
    expect($as['masuk'])->toBe(1);
    $ajId = $as['ditugaskan'][0]['id'];
    expect(dwCore('dw_ajuan', $ajId)['status'])->toBe('DISETUJUI')
        ->and(dwCore('dw_ajuan', $ajId)['permintaan_id'])->toBe($pmId);

    $svc->saveHadir($ajId, 'HADIR', '', $by);
    expect(dwCore('dw_ajuan', $ajId)['hadir'])->toBe('HADIR');
});

it('blocks overlapping assignments on core', function () {
    $svc = app(DwService::class);
    $by = officeUser('u-rizkiarfan')['name'];
    $dw = DB::connection('legacy_dw')->table('dw_pekerja')->where('status', 'AKTIF')->value('id');

    $one = $svc->saveAjuan(['dwId' => $dw, 'tgl' => '2031-10-01', 'm' => '18:00', 's' => '23:00', 'divisi' => 'bar'], $by);
    expect($one['saved'])->toBeTrue();

    $two = $svc->saveAjuan(['dwId' => $dw, 'tgl' => '2031-10-01', 'm' => '19:00', 's' => '20:00', 'divisi' => 'bar'], $by);
    expect($two['saved'])->toBeFalse()->and($two)->toHaveKey('bentrok');
});

it('ticks payments and saves settings on core', function () {
    $sesi = legacySesi(officeUser('u-rizkiarfan'));
    $this->legacyPost('/dw-api-mysql/api.php', ['action' => 'tandaiBayar', 'sesi' => $sesi,
        'senin' => '2032-02-02', 'kunci' => 'BANK::1', 'nyala' => true])->assertOk()->assertJsonPath('data.nyala', 1);

    $blob = json_decode(dwCore('dw_setting', '1')['data'], true);
    expect($blob['bayarLunas']['2032-02-02|BANK::1']['oleh'])->toBe(officeUser('u-rizkiarfan')['name']);
});

it('runs the v1 worker round-trip on core', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));

    $created = $this->withToken($token)->postJson('/api/v1/dw/workers', ['nama' => 'V1 Core', 'hp' => '080000000043'])
        ->assertCreated()->json('data');
    $row = dwCore('dw_pekerja', $created['id']);
    expect($row['nama'])->toBe('V1 Core');

    $this->withToken($token)->putJson("/api/v1/dw/workers/{$created['id']}", ['id' => $created['id'], 'nama' => 'V1 Core 2', 'hp' => '080000000043'])
        ->assertOk()->assertJsonPath('data.saved', true)->assertJsonPath('data.id', $created['id']);
    expect(dwCore('dw_pekerja', $created['id'])['nama'])->toBe('V1 Core 2');

    $this->withToken($token)->getJson("/api/v1/dw/workers/{$created['id']}")
        ->assertOk()->assertJsonPath('data.nama', 'V1 Core 2');
});

it('wipes assignments and requests on core but keeps workers and settings', function () {
    $svc = app(DwService::class);
    $svc->kosongkanSemua(false, officeUser('u-admin')['name']);

    $core = DB::connection('core');
    expect($core->table('dw_ajuan')->count())->toBe(0)
        ->and($core->table('dw_permintaan')->count())->toBe(0)
        ->and($core->table('dw_pekerja')->count())->toBeGreaterThan(0)
        ->and($core->table('dw_setting')->count())->toBe(1);
});
