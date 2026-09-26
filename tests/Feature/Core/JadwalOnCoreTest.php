<?php

use App\Modules\Jadwal\Services\JadwalService;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/*
 * #47: jadwal served from core. Each test imports the restored account +
 * jadwal and switches both Moduls to core; the default (legacy) connection
 * is the rollback path, covered by the rest of the suite.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'account'])->assertSuccessful();
    $this->artisan('core:import', ['module' => 'jadwal'])->assertSuccessful();
    config(['laksamana.modules.account.connection' => 'core']);
    config(['laksamana.modules.jadwal.connection' => 'core']);
});

it('serves the legacy getAll from core with legacy user ids', function () {
    $sesi = legacySesi(officeUser('u-yuzaalfarel'));
    $res = $this->get('/jadwal-api-mysql/api.php?action=getAll&dari=2026-09-01&sampai=2026-09-30&sesi='.$sesi)
        ->assertOk()->json();

    expect($res['ok'])->toBeTrue()
        ->and($res['data']['setting']['heads']['bar'])->toContain('u-arif')
        ->and(array_column($res['data']['sel'], 'u'))->toContain('u-yuzaalfarel');
});

it('writes cells to core and reads them back with legacy ids', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-yuzaalfarel', 'd' => '2026-10-05', 't' => 'PAGI', 'm' => '08:00', 's' => '16:00', 'n' => 'core']],
    ])->assertOk()->assertJsonPath('data.isi', 1);

    $row = DB::connection('core')->table('jadwal_sel as s')
        ->join('user as u', 'u.id', '=', 's.user_id')
        ->where('u.legacy_id', 'u-yuzaalfarel')->where('s.tgl', '2026-10-05')->first(['s.shift', 's.catatan', 's.version']);
    expect($row->shift)->toBe('PAGI')->and($row->catatan)->toBe('core')->and($row->version)->toBe(1);

    $this->withToken(loginAs(officeUser('u-arif')))->getJson('/api/v1/jadwal/cells?from=2026-10-05&to=2026-10-05')
        ->assertOk()->assertJsonPath('data.0.u', 'u-yuzaalfarel')->assertJsonPath('data.0.n', 'core');
});

it('still refuses a head writing another division on core', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-mella', 'd' => '2026-10-05', 't' => 'PAGI']],
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('saves a crew request to core forced onto the caller as MENUNGGU', function () {
    $res = $this->withToken(loginAs(officeUser('u-yuzaalfarel')))->postJson('/api/v1/jadwal/requests', [
        'userId' => 'u-arif', 'jenis' => 'cuti', 'dari' => '2026-10-12', 'alasan' => 'core',
    ])->assertCreated();
    $id = $res->json('data.id');

    $row = DB::connection('core')->table('jadwal_pengajuan')->where('legacy_id', $id)->first();
    expect($row->status)->toBe('MENUNGGU');
    $uid = DB::connection('core')->table('user')->where('legacy_id', 'u-yuzaalfarel')->value('id');
    expect($row->user_id)->toBe($uid);
});

it('lets the head forward and HRD approve a request on core', function () {
    DB::connection('core')->table('jadwal_pengajuan')->insert([
        'id' => '01K0000000000000000000002', 'legacy_id' => 'Acore1',
        'user_id' => DB::connection('core')->table('user')->where('legacy_id', 'u-yuzaalfarel')->value('id'),
        'jenis' => 'CUTI', 'tgl_mulai' => '2026-10-12', 'tgl_selesai' => '2026-10-12', 'status' => 'MENUNGGU',
    ]);

    $this->withToken(loginAs(officeUser('u-arif')))
        ->postJson('/api/v1/jadwal/requests/Acore1/decision', ['status' => 'MENUNGGU_HRD'])
        ->assertOk()->assertJsonPath('data.status', 'MENUNGGU_HRD');

    expect(DB::connection('core')->table('jadwal_pengajuan')->where('legacy_id', 'Acore1')->value('status'))
        ->toBe('MENUNGGU_HRD');
});

it('lets HRD approve a forwarded request on core', function () {
    DB::connection('core')->table('jadwal_pengajuan')->insert([
        'id' => '01K0000000000000000000003', 'legacy_id' => 'Acore2',
        'user_id' => DB::connection('core')->table('user')->where('legacy_id', 'u-yuzaalfarel')->value('id'),
        'jenis' => 'CUTI', 'tgl_mulai' => '2026-10-12', 'tgl_selesai' => '2026-10-12', 'status' => 'MENUNGGU_HRD',
    ]);

    $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->postJson('/api/v1/jadwal/requests/Acore2/decision', ['status' => 'DISETUJUI'])
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
});

it('saves the setting to the normalised tables and rebuilds the blob', function () {
    $this->withToken(loginAs(officeUser('u-rizkiarfan')))->putJson('/api/v1/jadwal/settings', [
        'shifts' => ['PAGI' => ['n' => 'PAGI', 'm' => '08:00', 's' => '16:00', 'w' => '#00B0F0', 'libur' => 0, 'urut' => 10]],
        'heads' => ['bar' => ['u-arif']],
        'divOverride' => [],
        'jabatan' => ['u-yuzaalfarel' => 'Senior Bar'],
        'shiftKru' => ['u-yuzaalfarel' => 'PAGI'],
        'manajemen' => ['u-admin'],
        'maksBeruntun' => 6,
        'jedaMin' => 10,
    ])->assertOk();

    $core = DB::connection('core');
    expect($core->table('jadwal_shift')->where('kode', 'PAGI')->value('nama'))->toBe('PAGI')
        ->and($core->table('jadwal_jabatan')->count())->toBe(1)
        ->and($core->table('jadwal_shift_kru')->count())->toBe(1)
        ->and($core->table('jadwal_manajemen')->count())->toBe(1);

    $data = json_decode(json_encode(app(JadwalService::class)->setting()), true);
    expect($data['shifts']['PAGI']['m'])->toBe('08:00')
        ->and($data['jabatan'])->toBe(['u-yuzaalfarel' => 'Senior Bar'])
        ->and($data['shiftKru'])->toBe(['u-yuzaalfarel' => 'PAGI'])
        ->and($data['manajemen'])->toBe(['u-admin'])
        ->and($data['heads'])->toBe(['bar' => ['u-arif']]);
});

it('serves shiftHari for absensi from core', function () {
    $this->get('/jadwal-api-mysql/api.php?action=shiftHari&dari=2026-09-01&sampai=2026-09-30')
        ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['dari', 'sampai', 'rows']]);
});

it('keeps the roster Divisi identical on core', function () {
    $token = loginAs(officeUser('u-arif'));
    $rows = $this->withToken($token)->getJson('/api/v1/jadwal/roster')->assertOk()->json('data');
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row['id']] = $row['divisi'];
    }
    expect($byId['u-arif'])->toBe('bar')->and($byId['u-rizkiarfan'])->toBe('nonshift');
});

it('wipes cells and requests on core but keeps the settings', function () {
    Modules::db('jadwal')->table('jadwal_sel')->insert([
        'id' => '01K0000000000000000000001', 'legacy_id' => 'u-yuzaalfarel|2026-11-02',
        'user_id' => DB::connection('core')->table('user')->where('legacy_id', 'u-yuzaalfarel')->value('id'),
        'tgl' => '2026-11-02', 'shift' => 'PAGI',
    ]);

    $this->withToken(loginAs(officeUser('u-rizkiarfan')))->deleteJson('/api/v1/jadwal/cells', ['konfirmasi' => 'HAPUS SEMUA'])
        ->assertOk()->assertJsonPath('data.cleared', true);

    $core = DB::connection('core');
    expect($core->table('jadwal_sel')->count())->toBe(0)
        ->and($core->table('jadwal_pengajuan')->count())->toBe(0)
        ->and($core->table('jadwal_shift')->count())->toBeGreaterThan(0);
});
