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
