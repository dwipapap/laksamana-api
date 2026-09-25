<?php

use App\Support\Modules;
use Illuminate\Support\Str;

/*
 * ADR-0004's guard is deliberately exercised against one fully ported Modul.
 * Reads stay available while both legacy compat and v1 writes receive the error
 * already understood by the old Marketing frontend.
 */

beforeEach(function () {
    config(['laksamana.modules.marketing.maintenance' => true]);
});

it('refuses Marketing writes but keeps compat and v1 reads available', function () {
    $message = 'Server sedang sibuk menyimpan, coba lagi sebentar.';
    $id = 'c_maintenance_'.strtolower((string) Str::ulid());
    $before = Modules::db('marketing')->table('clients')->count();

    $this->legacyPost('/marketing-api-mysql/api.php', [
        'action' => 'saveAll',
        'data' => ['clients' => [[
            'id' => $id,
            'nama' => 'Blocked legacy write',
            'updatedAt' => 900000000000001,
        ]]],
    ])->assertOk()
        ->assertExactJson(['ok' => false, 'error' => $message]);

    $token = loginAs(officeUser('u-aurel'));
    $this->withToken($token)
        ->postJson('/api/v1/marketing/clients', [
            'nama' => 'Blocked v1 write',
            'perusahaan' => 'PT API',
            'status' => 'Lead',
        ])
        ->assertStatus(503)
        ->assertExactJson([
            'error' => [
                'code' => 'module_maintenance',
                'message' => $message,
            ],
        ]);

    $this->get('/marketing-api-mysql/api.php?action=saveAll')
        ->assertOk()
        ->assertExactJson(['ok' => false, 'error' => $message]);

    $this->get('/marketing-api-mysql/api.php?action=ping')
        ->assertOk()
        ->assertJsonPath('ok', true);
    $this->withToken($token)
        ->getJson('/api/v1/marketing/clients')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['page', 'perPage', 'total']]);

    expect(Modules::db('marketing')->table('clients')->count())->toBe($before)
        ->and(Modules::db('marketing')->table('clients')->where('id', $id)->exists())->toBeFalse();
});

it('keeps DW reads without running their write-on-read expiry sweep', function () {
    config(['laksamana.modules.dw.maintenance' => true]);
    Modules::db('dw')->insert(
        "INSERT INTO dw_ajuan (id,dw_id,tgl,jam_mulai,jam_selesai,divisi,status) VALUES ('AJmaintenance1','DWmtxzw6fd369','2020-01-06','18:00','23:00','kitchen','MENUNGGU')"
    );
    Modules::db('dw')->insert(
        "INSERT INTO dw_permintaan (id,divisi,tgl,jam_mulai,jam_selesai,jumlah,status) VALUES ('PMmaintenance1','kitchen','2020-01-06','18:00','23:00',2,'MENUNGGU')"
    );
    $sesi = legacySesi(officeUser('u-rizkiarfan'));

    $this->get('/dw-api-mysql/api.php?action=getAll&dari=2026-09-01&sampai=2026-09-30&sesi='.$sesi)
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Modules::db('dw')->table('dw_ajuan')->where('id', 'AJmaintenance1')->value('status'))->toBe('MENUNGGU')
        ->and(Modules::db('dw')->table('dw_permintaan')->where('id', 'PMmaintenance1')->value('status'))->toBe('MENUNGGU');
});

it('keeps each legacy error envelope and its headers when Stock is frozen', function () {
    config(['laksamana.modules.stock.maintenance' => true]);

    $this->get('/stock-api-mysql/items.php?action=ping')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $this->legacyPost('/stock-api-mysql/stock.php', ['stock' => [], 'as_of' => '2026-09-25'])
        ->assertStatus(503)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Server sedang sibuk menyimpan, coba lagi sebentar.',
        ]);
});
