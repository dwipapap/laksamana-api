<?php

use App\Auth\AccountRepository;
use App\Modules\Jadwal\Services\JadwalService;

require_once __DIR__.'/helpers.php';

// Serving jadwal from core implies identity on core too (the jadwal core tables
// FK `user` and take heads/Penempatan Divisi from identity). TestCase imports it
// once per process; the application is rebuilt per test, so re-apply the
// connection here for every test (#156).
beforeEach(function () {
    if (JadwalService::onCore() && ! AccountRepository::onCore()) {
        config(['laksamana.modules.account.connection' => 'core']);
    }
});

/*
 * Identities from the restored prod dump (jadwal_setting.heads):
 *   u-arif = head of Bar · u-yuzaalfarel = Bar crew · u-mella = head of Floor
 *   u-rizkiarfan = HRD (Tim "Office, HRD" => admin of jadwal)
 *
 * Row reads/writes go through helpers.php so the folder runs on both storages
 * (legacy and DB_JADWAL_CONNECTION=core, #156).
 */

it('requires the jadwal module', function () {
    $this->getJson('/api/v1/jadwal/cells?from=2026-09-01&to=2026-09-30')->assertStatus(401);
});

it('lets a head write cells of their own division', function () {
    $token = loginAs(officeUser('u-arif'));
    $this->withToken($token)->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-yuzaalfarel', 'd' => '2026-10-05', 't' => 'PAGI', 'm' => '08:00', 's' => '16:00']],
    ])->assertOk()->assertJsonPath('data.isi', 1);

    $row = jadwalSelRow('u-yuzaalfarel', '2026-10-05');
    expect($row->shift)->toBe('PAGI')
        ->and($row->updated_by)->toBe(officeUser('u-arif')['name']);
});

it('keeps start time, end time and note when a Kepala Divisi saves a full cell through v1', function () {
    $token = loginAs(officeUser('u-arif'));
    $this->withToken($token)->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-yuzaalfarel', 'd' => '2026-10-15', 't' => 'PAGI', 'm' => '08:00', 's' => '16:00', 'n' => 'apel pagi']],
    ])->assertOk()->assertJsonPath('data.isi', 1);

    $row = jadwalSelRow('u-yuzaalfarel', '2026-10-15');
    expect($row->jam_mulai)->toBe('08:00')
        ->and($row->jam_selesai)->toBe('16:00')
        ->and($row->catatan)->toBe('apel pagi');

    $this->withToken($token)->getJson('/api/v1/jadwal/cells?from=2026-10-15&to=2026-10-15')
        ->assertOk()
        ->assertJsonPath('data.0.m', '08:00')
        ->assertJsonPath('data.0.s', '16:00')
        ->assertJsonPath('data.0.n', 'apel pagi');
});

it('refuses a head writing another division', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-mella', 'd' => '2026-10-05', 't' => 'PAGI']],
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('refuses plain crew writing cells', function () {
    $this->withToken(loginAs(officeUser('u-yuzaalfarel')))->putJson('/api/v1/jadwal/cells', [
        'cells' => [['u' => 'u-yuzaalfarel', 'd' => '2026-10-05', 't' => 'OFF']],
    ])->assertStatus(403);
});

it('forces crew requests onto the caller and to MENUNGGU', function () {
    $res = $this->withToken(loginAs(officeUser('u-yuzaalfarel')))->postJson('/api/v1/jadwal/requests', [
        'userId' => 'u-arif', 'jenis' => 'cuti', 'dari' => '2026-10-12', 'alasan' => 'test',
    ])->assertCreated();
    $row = jadwalPengajuan($res->json('data.id'));
    expect($row->user_id)->toBe('u-yuzaalfarel')->and($row->status)->toBe('MENUNGGU')->and($row->jenis)->toBe('CUTI');
});

it('enforces head-then-HRD approval order', function () {
    jadwalInsertPengajuan('Atest1', 'u-yuzaalfarel', 'OFF', '2026-10-12', '2026-10-12', 'MENUNGGU');

    // HRD may not take the head's step while the division has a head
    $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->postJson('/api/v1/jadwal/requests/Atest1/decision', ['status' => 'MENUNGGU_HRD'])
        ->assertStatus(422);
});

it('lets the head forward a request to HRD', function () {
    jadwalInsertPengajuan('Atest2', 'u-yuzaalfarel', 'OFF', '2026-10-12', '2026-10-12', 'MENUNGGU');

    $this->withToken(loginAs(officeUser('u-arif')))
        ->postJson('/api/v1/jadwal/requests/Atest2/decision', ['status' => 'MENUNGGU_HRD', 'nota' => 'ok'])
        ->assertOk()->assertJsonPath('data.status', 'MENUNGGU_HRD');
    $row = jadwalPengajuan('Atest2');
    expect($row->head_oleh)->toBe(officeUser('u-arif')['name']);
});

it('lets HRD approve a forwarded request', function () {
    jadwalInsertPengajuan('Atest3', 'u-yuzaalfarel', 'OFF', '2026-10-12', '2026-10-12', 'MENUNGGU_HRD');

    $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->postJson('/api/v1/jadwal/requests/Atest3/decision', ['status' => 'DISETUJUI'])
        ->assertOk()->assertJsonPath('data.status', 'DISETUJUI');
});

it('keeps settings writable by jadwal admins only', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->putJson('/api/v1/jadwal/settings', ['heads' => []])
        ->assertStatus(403);
});

it('stores empty setting maps as JSON objects', function () {
    $this->withToken(loginAs(officeUser('u-rizkiarfan')))
        ->putJson('/api/v1/jadwal/settings', ['shifts' => ['PAGI' => ['m' => '08:00']], 'heads' => ['bar' => ['u-arif']], 'jabatan' => []])
        ->assertOk();
    $raw = jadwalSettingRaw();
    expect($raw)->toContain('"jabatan":{}');
});

it('serves the open legacy shiftHari with filled default times', function () {
    $this->get('/jadwal-api-mysql/api.php?action=shiftHari&dari=2026-09-01&sampai=2026-09-30')
        ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['dari', 'sampai', 'rows']]);
});

it('rejects the legacy getAll without a session using the sesi_tidak_sah prefix', function () {
    $err = $this->get('/jadwal-api-mysql/api.php?action=getAll')->json('error');
    expect($err)->toStartWith('sesi_tidak_sah:');
});

it('accepts an old Office session token on the legacy route', function () {
    $sesi = legacySesi(officeUser('u-yuzaalfarel'));
    $this->get('/jadwal-api-mysql/api.php?action=getAll&dari=2026-09-01&sampai=2026-09-30&sesi='.$sesi)
        ->assertOk()->assertJsonPath('ok', true);
});

it('lets an Admin Modul wipe all cells and requests with the confirmation words', function () {
    jadwalInsertSel('u-yuzaalfarel', '2026-11-02', 'PAGI');
    jadwalInsertPengajuan('Awipe1', 'u-yuzaalfarel', 'OFF', '2026-11-02', '2026-11-02', 'MENUNGGU');

    $this->withToken(loginAs(officeUser('u-rizkiarfan')))->deleteJson('/api/v1/jadwal/cells', ['konfirmasi' => 'HAPUS SEMUA'])
        ->assertOk()->assertJsonPath('data.cleared', true)->assertJsonStructure(['data' => ['sel', 'pengajuan']]);

    expect(jadwalSelCount('u-yuzaalfarel', '2026-11-02'))->toBe(0)
        ->and(jadwalPengajuanCount('Awipe1'))->toBe(0)
        // settings are kept, like the legacy route
        ->and(jadwalSettingRowCount())->toBe(1);
});

it('refuses the wipe with wrong confirmation and keeps the data', function () {
    jadwalInsertPengajuan('Awipe2', 'u-yuzaalfarel', 'OFF', '2026-11-03', '2026-11-03', 'MENUNGGU');

    $this->withToken(loginAs(officeUser('u-rizkiarfan')))->deleteJson('/api/v1/jadwal/cells', ['konfirmasi' => 'yes'])
        ->assertStatus(422);

    expect(jadwalPengajuanCount('Awipe2'))->toBe(1);
});

it('refuses the wipe for non-admins', function () {
    $this->withToken(loginAs(officeUser('u-arif')))->deleteJson('/api/v1/jadwal/cells', ['konfirmasi' => 'HAPUS SEMUA'])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('filters requests by mine, status, user and date range', function () {
    jadwalInsertPengajuan('Aflt1', 'u-yuzaalfarel', 'CUTI', '2026-11-05', '2026-11-07', 'MENUNGGU');
    jadwalInsertPengajuan('Aflt2', 'u-yuzaalfarel', 'OFF', '2026-11-20', '2026-11-20', 'DISETUJUI');
    jadwalInsertPengajuan('Aflt3', 'u-mella', 'CUTI', '2026-11-06', '2026-11-06', 'DITOLAK');
    jadwalInsertPengajuan('Aflt4', 'u-mella', 'OFF', '2026-12-01', '2026-12-03', 'MENUNGGU_HRD');
    $token = loginAs(officeUser('u-yuzaalfarel'));
    $ids = fn ($q) => array_column($this->withToken($token)->getJson('/api/v1/jadwal/requests'.$q)->assertOk()->json('data'), 'id');

    expect($ids('?mine=1'))->toContain('Aflt1', 'Aflt2')->not->toContain('Aflt3', 'Aflt4');
    expect($ids('?user=u-mella'))->toContain('Aflt3', 'Aflt4')->not->toContain('Aflt1');
    expect($ids('?status=MENUNGGU,DISETUJUI'))->toContain('Aflt1', 'Aflt2')->not->toContain('Aflt3', 'Aflt4');
    expect($ids('?from=2026-11-06&to=2026-11-06'))->toContain('Aflt1', 'Aflt3')->not->toContain('Aflt2', 'Aflt4');
    expect($ids('?user=nobody'))->toBe([]);
});

it('serves the roster with the same Divisi legacy jdw_divisi_user gives every User', function () {
    $token = loginAs(officeUser('u-arif'));
    $rows = $this->withToken($token)->getJson('/api/v1/jadwal/roster')->assertOk()->json('data');
    expect($rows)->not->toBe([]);

    // Independent port of jdw_divisi_user (lib_jadwal_mysql.php), plus the
    // owner-decided #3 fix (Tim "FOH" -> floor, which legacy does not have yet):
    // the test guards the endpoint wiring, the anchors below guard the algorithm.
    $override = jadwalSetting()['divOverride'] ?? [];
    $legacyDivisi = function (array $u) use ($override): string {
        if (isset($override[$u['id']]) && $override[$u['id']] !== '') {
            return (string) $override[$u['id']];
        }
        $kata = preg_split('/[^a-z]+/', strtolower((string) ($u['keterangan'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (['office', 'kantor'] as $x) {
            if (in_array($x, $kata, true)) {
                return 'nonshift';
            }
        }
        $sin = ['bar' => ['bar', 'bartender'], 'kitchen' => ['kitchen', 'dapur'],
            'floor' => ['floor', 'service', 'waiter', 'waitress', 'host', 'hostess', 'foh'],
            'cashier' => ['cashier', 'kasir']];
        foreach ($sin as $kode => $daftar) {
            foreach ($daftar as $x) {
                if (in_array($x, $kata, true)) {
                    return $kode;
                }
            }
        }

        return 'nonshift';
    };

    $byId = [];
    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['id', 'name', 'keterangan', 'active', 'divisi']);
        expect($row['divisi'])->toBe($legacyDivisi($row), 'divisi of '.$row['id']);
        $byId[$row['id']] = $row['divisi'];
    }
    // Anchors verified against the restored dump: Tim "Bar" -> bar, "Floor, FOH"
    // -> floor, "Office, HRD" -> nonshift (office wins), empty/Superadmin -> nonshift.
    expect($byId['u-arif'])->toBe('bar')->and($byId['u-yuzaalfarel'])->toBe('bar')
        ->and($byId['u-mella'])->toBe('floor')->and($byId['u-rizkiarfan'])->toBe('nonshift')
        ->and($byId['u-adit'])->toBe('kitchen')->and($byId['u-aurel'])->toBe('nonshift')
        ->and($byId['u-andry'])->toBe('nonshift')->and($byId['u-admin'])->toBe('nonshift');
});
