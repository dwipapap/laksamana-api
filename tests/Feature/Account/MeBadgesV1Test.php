<?php

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;

/*
 * api#311: GET /api/v1/me/badges returns the Beranda "Perlu tindakan"
 * counts ({modul: {layar: n}}) in one call, for the modules the caller may
 * open. The one asserted number is read relatively (before/after seeding),
 * so real rows in the restored DB never disturb it.
 */

beforeEach(function () {
    config(['laksamana.modules.reservasi.data_dir' => storage_path('framework/testing/reservasi-db')]);
});

function bdToken(array $u): string
{
    $token = loginAs($u);
    app('auth')->forgetGuards();

    return $token;
}
function bdUser(string $id, array $modules): array
{
    $repo = app(AccountRepository::class);
    $repo->insertUser(['id' => $id, 'name' => 'Uji '.$id, 'pin' => '4817', 'active' => 1, 'keterangan' => '']);
    foreach ($modules as $m) {
        $repo->upsertGrant($id, $m, true, 'test');
    }
    app(OfficeAccess::class)->forgetUser($id);

    return officeUser($id);
}

it('311: badges count today Pending and only list openable modules', function () {
    $token = bdToken(bdUser('u-bd-rsv', ['reservasi']));
    $before = $this->withToken($token)->getJson('/api/v1/me/badges')->assertOk()->json('data');

    expect($before)->toHaveKey('reservasi')
        ->and($before)->not->toHaveKey('marketing')
        ->and($before)->not->toHaveKey('absensi')
        ->and($before['reservasi'])->toHaveKeys(['dashboard', 'lonceng']);

    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    $this->withToken($token)->postJson('/api/v1/reservasi/reservations', [
        'name' => 'T311 Badge', 'phone' => '0831-200-0001', 'date' => $today, 'time' => '19:00',
        'status' => 'Pending', 'pax' => 2, 'source' => 'UJI-311',
    ])->assertCreated();

    $after = $this->withToken($token)->getJson('/api/v1/me/badges')->assertOk()->json('data');
    expect($after['reservasi']['dashboard'])->toBe($before['reservasi']['dashboard'] + 1)
        ->and($after['reservasi']['lonceng'])->toBe($before['reservasi']['lonceng'] + 1);
});
