<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;

require_once __DIR__.'/helpers.php';

/*
 * G-07 (#182): GET /api/v1/reservasi/guests/summary returns the legacy
 * `ringkasTamu` shape over the whole history, so the windowed client can
 * merge it with its own rows exactly like the old screen (profilGabung).
 */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

function gsToken(array $u): string
{
    // The guard caches its user: drop it AFTER loginAs (whose own request
    // runs with this test's previous bearer), or the next call still
    // answers as whoever made the previous request.
    $token = loginAs($u);
    app('auth')->forgetGuards();

    return $token;
}

function gsUser(string $id, array $modules): array
{
    $repo = app(AccountRepository::class);
    $repo->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => '']);
    foreach ($modules as $m) {
        $repo->upsertGrant($id, $m, true, 'test');
    }
    app(OfficeAccess::class)->forgetUser($id);

    return officeUser($id);
}

function gsSeed(string $token): void
{
    $rows = [
        ['name' => 'Budi', 'phone' => '0812-345-6789', 'date' => '2026-09-01', 'time' => '19:00',
            'status' => 'Datang', 'pax' => 4, 'member' => 1, 'memberNo' => 'M-1', 'createdAt' => 1000],
        ['name' => 'Budi Santoso', 'phone' => '+62 812 345 6789', 'date' => '2026-09-05', 'time' => '19:00',
            'status' => 'No-show', 'pax' => 2, 'vip' => 1, 'createdAt' => 2000],
        ['name' => 'Citra', 'phone' => '0821-000-1111', 'date' => '2026-09-03', 'time' => '20:00',
            'status' => 'Checked-in', 'pax' => 3, 'createdAt' => 3000],
        ['name' => 'Dedi', 'phone' => '0822-000-2222', 'date' => '2026-10-20', 'time' => '19:00',
            'status' => 'Booking', 'pax' => 5, 'createdAt' => 4000],
    ];
    foreach ($rows as $row) {
        test()->withToken($token)->postJson('/api/v1/reservasi/reservations', $row)->assertCreated();
    }
}

it('G-07: summarises the whole history in the legacy shape', function () {
    $token = gsToken(officeUser('u-andry'));
    gsSeed($token);

    $res = $this->withToken($token)->getJson('/api/v1/reservasi/guests/summary')->assertOk();
    $tamu = $res->json('data.tamu');

    expect($res->json('data.sebelum'))->toBeNull()
        // Budi twice: 2 rows, 1 Datang, 1 No-show, member M-1, vip, pax 4+2, first/last name.
        // [6] is the last DATANG date only: the No-show row leaves it at 2026-09-01 (twin of ringkas_tamu).
        ->and($tamu['k628123456789'])->toEqual([2, 1, 1, 1, 'M-1', 1, '2026-09-01', 6, 'Budi', 'Budi Santoso'])
        // Checked-in counts as Datang (status normalisation, twin of rsv_norm_status).
        ->and($tamu['k628210001111'])->toEqual([1, 1, 0, 0, '', 0, '2026-09-03', 3, 'Citra', 'Citra'])
        // Booking counts as Confirmed: a visit, but neither Datang nor No-show.
        ->and($tamu['k628220002222'])->toEqual([1, 0, 0, 0, '', 0, '', 5, 'Dedi', 'Dedi']);
});

it('G-07: before bounds the history and phone narrows it to one number', function () {
    $token = gsToken(officeUser('u-andry'));
    gsSeed($token);

    $res = $this->withToken($token)->getJson('/api/v1/reservasi/guests/summary?before=2026-09-04')->assertOk();
    expect($res->json('data.sebelum'))->toBe('2026-09-04')
        ->and($res->json('data.tamu'))->toHaveKeys(['k628123456789', 'k628210001111'])
        ->and($res->json('data.tamu'))->not->toHaveKey('k628220002222')
        ->and($res->json('data.tamu.k628123456789'))->toEqual([1, 1, 0, 1, 'M-1', 0, '2026-09-01', 4, 'Budi', 'Budi']);

    // The phone is normalised the legacy way: digits, 0 → 62.
    $one = $this->withToken($token)->getJson('/api/v1/reservasi/guests/summary?phone=%2B62+812+345+6789')->assertOk();
    expect($one->json('data.tamu'))->toHaveKeys(['k628123456789'])
        ->and($one->json('data.tamu.k628123456789'))->toEqual([2, 1, 1, 1, 'M-1', 1, '2026-09-01', 6, 'Budi', 'Budi Santoso']);

    $none = $this->withToken($token)->getJson('/api/v1/reservasi/guests/summary?phone=0800-tak-ada')->assertOk();
    expect($none->json('data.tamu'))->toBe([]);

    $this->withToken($token)->getJson('/api/v1/reservasi/guests/summary?before=bukan-tanggal')->assertStatus(422);
});

it('G-07: opens to the same gate as the reservation reads', function () {
    $token = gsToken(officeUser('u-andry'));
    gsSeed($token);

    // Service Excellent holders read it (the other Panel of the same Backend)…
    $this->withToken(gsToken(officeUser('u-ernimianiangelapurba')))
        ->getJson('/api/v1/reservasi/guests/summary')->assertOk();
    // …and so do cashier/finance through the Dana Masuk door, like GET /reservations…
    $this->withToken(gsToken(gsUser('u-gs-cashier', ['cashier'])))
        ->getJson('/api/v1/reservasi/guests/summary')->assertOk();
    // …but a holder of neither is refused.
    $this->withToken(gsToken(gsUser('u-gs-none', ['hr'])))
        ->getJson('/api/v1/reservasi/guests/summary')->assertStatus(403);
});
