<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;

/*
 * Account v1 back-fill (#6): the Kelola User / Kelola Akses / Pengelola Roster
 * actions that had no v1 endpoint yet. Each endpoint is exercised with its
 * success path plus the legacy refusals; the last test pins the 403 gate for
 * every new route.
 *
 * Identities from the restored dump:
 *   u-admin      = superadmin (admins.module '*')
 *   u-rizkiarfan = Tim "Office, HRD" -> Admin Modul jadwal (Pengelola Roster)
 *   u-andry      = active crew, no admin, no jadwal admin (plain user)
 */

// ------------------------------------------------------------- superadmin

it('imports Users in bulk, counting bad rows instead of aborting (#6)', function () {
    $token = loginAs(officeUser('u-admin'));

    $res = $this->withToken($token)->postJson('/api/v1/account/users/bulk', [
        'users' => [
            ['name' => 'Bulk Satu', 'pin' => '2001', 'keterangan' => 'Kitchen'],
            ['name' => 'Bulk Dua', 'pin' => '2002', 'keterangan' => 'Bar'],
        ],
    ])->assertCreated()->json('data');
    expect($res['sukses'])->toBe(2)->and($res['gagal'])->toBe(0)
        ->and(app(AccountRepository::class)->userById('u-bulksatu'))->not->toBeNull()
        ->and(app(AccountRepository::class)->userById('u-bulkdua'))->not->toBeNull();

    // A duplicate name is counted in `baris`, exactly like the legacy saveUsers.
    $dup = $this->withToken($token)->postJson('/api/v1/account/users/bulk', [
        'users' => [['name' => 'Bulk Satu'], ['name' => 'Bulk Tiga']],
    ])->assertCreated()->json('data');
    expect($dup['sukses'])->toBe(1)->and($dup['gagal'])->toBe(1)
        ->and($dup['baris'][0]['error'])->toBe('name_taken');

    // Empty batch keeps the legacy `empty` code.
    $this->withToken($token)->postJson('/api/v1/account/users/bulk', ['users' => []])
        ->assertStatus(422)->assertJsonPath('error.code', 'empty');
});

it('runs the one-time Sheet import through v1 (#6)', function () {
    $token = loginAs(officeUser('u-admin'));

    $res = $this->withToken($token)->postJson('/api/v1/account/import', [
        'users' => [['id' => 'u-importtest', 'name' => 'Import Test', 'keterangan' => 'Kitchen', 'active' => true]],
        'modules' => [['key' => 'imptest', 'label' => 'Imp Test']],
        'grants' => [['userId' => 'u-importtest', 'module' => 'imptest', 'access' => true]],
        'admins' => [['userId' => 'u-importtest', 'module' => 'imptest']],
    ])->assertOk()->json('data');

    expect($res['diproses'])->toBe(['users' => 1, 'modules' => 1, 'grants' => 1, 'admins' => 1])
        ->and(app(AccountRepository::class)->userById('u-importtest'))->not->toBeNull()
        ->and(app(OfficeAccess::class)->hasModule('u-importtest', 'imptest'))->toBeTrue()
        ->and(app(OfficeAccess::class)->isModuleAdmin('u-importtest', 'imptest'))->toBeTrue();
});

it('registers new Modul keys without touching existing ones (#6)', function () {
    $token = loginAs(officeUser('u-admin'));

    $res = $this->withToken($token)->postJson('/api/v1/account/modules', [
        'modules' => [['key' => 'zznewmod', 'label' => 'ZZ New'], ['key' => 'jadwal', 'label' => 'Hijack']],
    ])->assertCreated()->json('data');
    expect($res['added'])->toBe(['zznewmod']);

    $byKey = collect($this->withToken($token)->getJson('/api/v1/account/modules?all=1')->assertOk()->json('data'))->keyBy('key');
    expect($byKey['zznewmod']['label'])->toBe('ZZ New')
        ->and($byKey['jadwal']['label'])->not->toBe('Hijack'); // existing key untouched

    // Re-adding an existing key adds nothing.
    $again = $this->withToken($token)->postJson('/api/v1/account/modules', ['modules' => [['key' => 'zznewmod']]])
        ->assertCreated()->json('data');
    expect($again['added'])->toBe([]);
});

it('edits a Modul label and active flag, refusing an unknown key (#6)', function () {
    $token = loginAs(officeUser('u-admin'));

    $this->withToken($token)->patchJson('/api/v1/account/modules/jadwal', ['label' => 'Jadwal Shift X'])->assertOk();
    $byKey = collect($this->withToken($token)->getJson('/api/v1/account/modules?all=1')->assertOk()->json('data'))->keyBy('key');
    expect($byKey['jadwal']['label'])->toBe('Jadwal Shift X');

    $this->withToken($token)->patchJson('/api/v1/account/modules/jadwal', ['active' => false])->assertOk();
    $byKey = collect($this->withToken($token)->getJson('/api/v1/account/modules?all=1')->assertOk()->json('data'))->keyBy('key');
    expect($byKey['jadwal']['active'])->toBeFalse();

    // Legacy not_found when the key is not registered.
    $this->withToken($token)->patchJson('/api/v1/account/modules/nope-key', ['label' => 'x'])
        ->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

// --------------------------------------------------------- pengelola roster

it('deletes a deactivated roster row as Pengelola Roster, with the legacy refusals (#6)', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));

    $this->withToken($token)->postJson('/api/v1/account/roster', ['name' => 'Roster Hapus', 'keterangan' => 'Floor'])
        ->assertCreated();

    // Active rows must be deactivated first.
    $this->withToken($token)->deleteJson('/api/v1/account/roster/u-rosterhapus')
        ->assertStatus(422)->assertJsonPath('error.code', 'must_deactivate_first');

    $this->withToken($token)->putJson('/api/v1/account/roster/u-rosterhapus/active', ['active' => false])->assertOk();
    $this->withToken($token)->deleteJson('/api/v1/account/roster/u-rosterhapus')
        ->assertOk()->assertJsonPath('data.nama', 'Roster Hapus');
    expect(app(AccountRepository::class)->userById('u-rosterhapus'))->toBeNull();

    // Cannot delete self, and cannot delete a superadmin.
    $this->withToken($token)->deleteJson('/api/v1/account/roster/u-rizkiarfan')
        ->assertStatus(422)->assertJsonPath('error.code', 'cannot_delete_self');
    $this->withToken($token)->deleteJson('/api/v1/account/roster/u-admin')
        ->assertStatus(422)->assertJsonPath('error.code', 'cannot_delete_admin');
});

it('toggles a roster row active as Pengelola Roster, refusing self and superadmin (#6)', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));

    $this->withToken($token)->putJson('/api/v1/account/roster/u-andry/active', ['active' => false])
        ->assertOk()->assertJsonPath('data.active', false);
    expect((int) app(AccountRepository::class)->userById('u-andry')['active'])->toBe(0);

    $this->withToken($token)->putJson('/api/v1/account/roster/u-andry/active', ['active' => true])->assertOk();
    expect((int) app(AccountRepository::class)->userById('u-andry')['active'])->toBe(1);

    $this->withToken($token)->putJson('/api/v1/account/roster/u-rizkiarfan/active', ['active' => false])
        ->assertStatus(422)->assertJsonPath('error.code', 'cannot_deactivate_self');
    $this->withToken($token)->putJson('/api/v1/account/roster/u-admin/active', ['active' => false])
        ->assertStatus(422)->assertJsonPath('error.code', 'cannot_deactivate_admin');
});

it('applies the rosterSetActive rules when active goes through the roster PATCH (#6)', function () {
    $token = loginAs(officeUser('u-rizkiarfan'));
    $me = officeUser('u-rizkiarfan');

    // An identity edit still works.
    $this->withToken($token)->patchJson('/api/v1/account/roster/u-andry', [
        'name' => 'Andry', 'keterangan' => '', 'active' => true,
    ])->assertOk();

    // Deactivating a superadmin or the caller through PATCH is refused like rosterSetActive.
    $this->withToken($token)->patchJson('/api/v1/account/roster/u-admin', [
        'name' => 'Admin', 'keterangan' => 'Superadmin', 'active' => false,
    ])->assertStatus(422)->assertJsonPath('error.code', 'cannot_deactivate_admin');

    $this->withToken($token)->patchJson('/api/v1/account/roster/u-rizkiarfan', [
        'name' => $me['name'], 'keterangan' => $me['keterangan'], 'talentaId' => $me['talenta_id'], 'active' => false,
    ])->assertStatus(422)->assertJsonPath('error.code', 'cannot_deactivate_self');

    // A plain row can still be deactivated through PATCH.
    $this->withToken($token)->patchJson('/api/v1/account/roster/u-andry', [
        'name' => 'Andry', 'keterangan' => '', 'active' => false,
    ])->assertOk();
    expect((int) app(AccountRepository::class)->userById('u-andry')['active'])->toBe(0);
});

// ------------------------------------------------------------------- gates

it('closes every new account endpoint to a plain user (#6)', function () {
    $token = loginAs(officeUser('u-andry'));

    $this->withToken($token)->postJson('/api/v1/account/users/bulk', ['users' => [['name' => 'Nope']]])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->withToken($token)->postJson('/api/v1/account/import', [])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->withToken($token)->postJson('/api/v1/account/modules', ['modules' => [['key' => 'nope']]])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->withToken($token)->patchJson('/api/v1/account/modules/jadwal', ['label' => 'nope'])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->withToken($token)->putJson('/api/v1/account/roster/u-admin/active', ['active' => false])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->withToken($token)->deleteJson('/api/v1/account/roster/u-admin')
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
