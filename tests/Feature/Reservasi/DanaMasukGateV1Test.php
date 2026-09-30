<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;

require_once __DIR__.'/helpers.php';

/*
 * G-14 / G-15: holders of `cashier` or `finance` reach the Dana Masuk endpoints
 * of Reservasi (and the DP Event read of Marketing) without holding those
 * modules — the old ?embed=finance door. The users are made here, inside the
 * test transaction, holding exactly one module each.
 */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

/** A throwaway active user holding exactly the given modules. */
function dmUser(string $id, array $modules): array
{
    $repo = app(AccountRepository::class);
    $repo->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => '']);
    foreach ($modules as $m) {
        $repo->upsertGrant($id, $m, true, 'test');
    }
    app(OfficeAccess::class)->forgetUser($id);

    return officeUser($id);
}

/**
 * Token for a user, dropping the guard's cached user first: this file switches
 * identity inside one test, and the guard would otherwise keep answering as
 * whoever made the previous request.
 */
function dmToken(array $u): string
{
    app('auth')->forgetGuards();

    return loginAs($u);
}

/** A fresh reservation made by a reservasi holder; returns [id, version]. */
function dmReservation(): array
{
    $created = test()->withToken(dmToken(officeUser('u-andry')))->postJson('/api/v1/reservasi/reservations', [
        'name' => 'Tamu Dana Masuk', 'date' => '2026-10-21', 'status' => 'Pending', 'table' => 'R1', 'pax' => 4,
    ])->assertCreated();

    return [$created->json('data.id'), $created->json('meta.version')];
}

it('G-14: cashier and finance read reservations, one reservation, dpMethods and files', function (string $module) {
    [$id] = dmReservation();
    $token = dmToken(dmUser("u-g14-$module", [$module]));

    $this->withToken($token)->getJson('/api/v1/reservasi/reservations?from=2026-10-21&to=2026-10-21')->assertOk();
    $this->withToken($token)->getJson("/api/v1/reservasi/reservations/$id")->assertOk()->assertJsonPath('data.id', $id);
    $this->withToken($token)->getJson('/api/v1/reservasi/master/dpMethods')->assertOk();
})->with(['cashier', 'finance']);

it('G-14: the rest of the contract stays closed to them', function () {
    [$id, $v] = dmReservation();
    $token = dmToken(dmUser('u-g14-closed', ['cashier']));

    foreach (['master/users', 'master/perms', 'master/tables'] as $path) {
        $this->withToken($token)->getJson("/api/v1/reservasi/$path")
            ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
    }
    $this->withToken($token)->getJson('/api/v1/reservasi/master')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/reservasi/audit')->assertStatus(403);
    $this->withToken($token)->postJson('/api/v1/reservasi/reservations', ['name' => 'x'])->assertStatus(403);
    $this->withToken($token)->putJson("/api/v1/reservasi/reservations/$id?version=$v", ['name' => 'x'])->assertStatus(403);
    $this->withToken($token)->deleteJson("/api/v1/reservasi/reservations/$id?version=$v")->assertStatus(403);
});

it('G-14: a cashier verifies a transfer and flips Pending to Confirmed, but cannot touch other fields', function () {
    [$id, $v] = dmReservation();
    $token = dmToken(dmUser('u-g14-write', ['cashier']));
    $dps = [['id' => 'p1', 'amount' => 150000, 'method' => 'QRIS', 'tfStatus' => 'verified']];

    $refused = $this->withToken($token)->patchJson("/api/v1/reservasi/reservations/$id?version=$v", [
        'dps' => $dps, 'name' => 'Diganti', 'table' => 'R2',
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    expect($refused->json('error.details.fields'))->toBe(['name', 'table']);

    $this->withToken($token)->patchJson("/api/v1/reservasi/reservations/$id?version=$v", ['status' => 'Cancelled'])
        ->assertStatus(403);

    $ok = $this->withToken($token)->patchJson("/api/v1/reservasi/reservations/$id?version=$v", [
        'dps' => $dps, 'dpStatus' => 'Sudah', 'dpAmount' => 150000, 'tfStatus' => 'verified', 'status' => 'Confirmed',
        'name' => 'Tamu Dana Masuk',   // unchanged: no write, so allowed
        '_audit' => ['action' => 'Verifikasi DP', 'detail' => 'uji'],
    ])->assertOk();
    expect($ok->json('data'))->toMatchArray(['status' => 'Confirmed', 'dpAmount' => 150000, 'name' => 'Tamu Dana Masuk', 'table' => 'R1']);

    $this->withToken($token)->postJson('/api/v1/reservasi/audit', ['action' => 'Scan Bukti TF', 'detail' => 'uji', 'res' => $id])
        ->assertCreated();
});

it('G-14: a reservasi holder still patches any field', function () {
    [$id, $v] = dmReservation();
    $this->withToken(dmToken(officeUser('u-andry')))->patchJson("/api/v1/reservasi/reservations/$id?version=$v", ['table' => 'R2'])
        ->assertOk()->assertJsonPath('data.table', 'R2');
});

it('G-14: a user with none of the four modules is still refused', function () {
    $this->withToken(dmToken(dmUser('u-g14-none', ['hr'])))->getJson('/api/v1/reservasi/reservations')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('G-15: DP Event opens to reservasi, cashier and finance, not to anyone', function () {
    foreach (['reservasi', 'cashier', 'finance'] as $m) {
        $this->withToken(dmToken(dmUser("u-g15-$m", [$m])))->getJson('/api/v1/marketing/dp?from=2026-01-01&to=2026-12-31')
            ->assertOk();
    }
    $token = dmToken(dmUser('u-g15-none', ['hr']));
    $this->withToken($token)->getJson('/api/v1/marketing/dp?from=2026-01-01&to=2026-12-31')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');

    // The rest of Marketing stays closed to a cashier.
    $this->withToken(dmToken(dmUser('u-g15-closed', ['cashier'])))->getJson('/api/v1/marketing/clients')
        ->assertStatus(403);
});
