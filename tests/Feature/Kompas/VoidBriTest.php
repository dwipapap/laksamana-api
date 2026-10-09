<?php

require_once __DIR__.'/helpers.php';

use App\Modules\Kompas\Services\KompasState;
use App\Modules\Kompas\Services\VoidBri;
use App\Support\Modules;
use Illuminate\Testing\TestResponse;

/* u-novi holds finance (not admin); u-wandi is superadmin; u-adit has neither cashier nor finance. */

function vbPost(array $body): TestResponse
{
    return test()->call('POST', '/kompas-api-mysql/api.php', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

it('splits bill-level service/tax cumulatively: exact sum, never negative', function () {
    expect(VoidBri::split([3333, 3333, 3334], 1000))->toBe([333, 334, 333])
        ->and(array_sum(VoidBri::split([1, 1, 1, 1, 1, 1, 1], 5)))->toBe(5)
        ->and(min(VoidBri::split([1, 1, 1, 1, 1, 1, 1], 5)))->toBeGreaterThanOrEqual(0)
        ->and(VoidBri::split([0, 0], 500))->toBe([500, 0])
        ->and(VoidBri::detail(['subtotal' => 100, 'service' => 5, 'tax' => 10, 'nominal' => 1]))->toMatchArray(['nominal' => 115])
        ->and(VoidBri::detail(['subtotal' => -1]))->toBeNull()
        ->and(VoidBri::jam('0.6013888'))->toBe('14:26')
        ->and(VoidBri::date('2026-02-31'))->toBe('');
});

it('legacy: a void is saved with the SESSION name and a server-computed nominal; cancelling needs a reason', function () {
    $sesi = legacySesi(officeUser('u-novi'));
    $id = vbPost(['action' => 'voidSimpan', 'sesi' => $sesi, 'data' => ['tgl' => '2026-09-12', 'bill' => 'B-1', 'item' => 'Kopi',
        'penginput' => 'A', 'salah' => 'B', 'alasan' => 'x', 'subtotal' => 10000, 'service' => 500, 'tax' => 1000, 'nominal' => 1, 'oleh' => 'spoof']])
        ->assertOk()->assertJsonPath('data.baru', true)->json('data.id');
    $row = kpRow('void_log', $id, 'nominal, oleh');
    expect((int) $row->nominal)->toBe(11500)->and($row->oleh)->toBe(officeUser('u-novi')['name']);

    vbPost(['action' => 'voidBatal', 'sesi' => $sesi, 'id' => $id, 'alasan' => ''])->assertExactJson(['ok' => false, 'error' => 'Alasan pembatalan wajib diisi.']);
});

it('legacy: missing fields come back as a kurang list', function () {
    vbPost(['action' => 'voidSimpan', 'sesi' => legacySesi(officeUser('u-novi')), 'data' => ['tgl' => '2026-09-12']])
        ->assertJsonPath('ok', false)->assertJsonPath('kurang', ['Nomor Bill', 'Nama Item', 'Siapa yang Menginput', 'Kesalahan dari Siapa', 'Alasan / Kronologi']);
});

it('legacy: a user without cashier/finance cannot write', function () {
    vbPost(['action' => 'briTambah', 'sesi' => legacySesi(officeUser('u-adit')), 'data' => []])
        ->assertJsonPath('error', fn ($e) => str_starts_with($e, 'tanpa_modul:'));
});

it('legacy: one DP can be matched to only one live mutation; an upload never overwrites a match', function () {
    $sesi = legacySesi(officeUser('u-novi'));
    vbPost(['action' => 'briUnggah', 'sesi' => $sesi, 'data' => ['baris' => [
        ['tgl' => '2026-09-10', 'jam' => '15:46', 'nominal' => 300000, 'ket' => 'A'],
        ['tgl' => '2026-09-10', 'jam' => '15:46', 'nominal' => 300000, 'ket' => 'B']]]])
        ->assertOk()->assertJsonPath('data.baru', 2);
    $ids = array_column(Modules::db('kompas')->select('SELECT `'.KompasState::idCol().'` AS id FROM `'.KompasState::t('bri_mutasi')
        .'` WHERE sidik LIKE ? ORDER BY sidik', ['2026-09-10|15:46|300000|#%']), 'id');

    vbPost(['action' => 'briCocok', 'sesi' => $sesi, 'data' => ['id' => $ids[0], 'cara' => 'cocok', 'resId' => 'r1', 'dpId' => 'dp1']])->assertJsonPath('ok', true);
    vbPost(['action' => 'briCocok', 'sesi' => $sesi, 'data' => ['id' => $ids[1], 'cara' => 'cocok', 'resId' => 'r1', 'dpId' => 'dp1']])
        ->assertJsonPath('ok', false)->assertJsonPath('error', fn ($e) => str_starts_with($e, 'DP itu sudah dicocokkan'));

    vbPost(['action' => 'briUnggah', 'sesi' => $sesi, 'data' => ['baris' => [['tgl' => '2026-09-10', 'jam' => '15.46', 'nominal' => 300000, 'ket' => 'A2']]]])
        ->assertJsonPath('data.lama', 1);
    expect(kpRow('bri_mutasi', $ids[0], 'ket, dp_id, cara'))
        ->ket->toBe('A2')->dp_id->toBe('dp1')->cara->toBe('cocok');
});

it('v1: finance records and edits a void with the row version', function () {
    $token = loginAs(officeUser('u-novi'));
    $id = $this->withToken($token)->postJson('/api/v1/kompas/voids', ['tgl' => '2026-09-12', 'bill' => 'B-1', 'item' => 'Kopi', 'penginput' => 'A', 'salah' => 'B', 'alasan' => 'x', 'subtotal' => 1000])
        ->assertCreated()->json('data.id');
    $v = (int) kpRow('void_log', $id, 'diubah')->diubah;
    $this->withToken($token)->putJson("/api/v1/kompas/voids/$id", ['alasan' => 'y'])->assertStatus(428);
    $this->withToken($token)->putJson("/api/v1/kompas/voids/$id?version=1", ['alasan' => 'y'])->assertStatus(409);
    $this->withToken($token)->putJson("/api/v1/kompas/voids/$id?version=$v", ['tgl' => '2026-09-12', 'bill' => 'B-1', 'item' => 'Kopi', 'penginput' => 'A', 'salah' => 'B', 'alasan' => 'y', 'subtotal' => 2000])
        ->assertOk()->assertJsonPath('data.baru', false);
    $this->withToken($token)->postJson('/api/v1/kompas/voids', ['tgl' => '2026-09-12'])->assertStatus(422)->assertJsonPath('error.details.kurang.0', 'Nomor Bill');
    $this->withToken($token)->putJson('/api/v1/kompas/voids/settings?version=0', ['tax' => 10, 'service' => 5])->assertStatus(403);
});

it('v1: users without cashier/finance are refused', function () {
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/kompas/bri')->assertStatus(403);
});

it('v1: a manual BRI fund is recorded as the session user and listed', function () {
    $token = loginAs(officeUser('u-novi'));
    $this->withToken($token)->postJson('/api/v1/kompas/bri', ['tgl' => '2026-09-12', 'nominal' => 500000, 'ket' => 'Sewa videotron'])->assertCreated();
    $list = $this->withToken($token)->getJson('/api/v1/kompas/bri?from=2026-09-01&to=2026-09-30')->assertOk();
    $row = collect($list->json('data.baris'))->firstWhere('ket', 'Sewa videotron');
    expect($row['cara'])->toBe('bukan')->and($row['sumber'])->toBe('manual')->and($row['oleh'])->toBe(officeUser('u-novi')['name']);
});

it('v1 + legacy briList returns dipakai[]: dpIds held by any live row, whatever month (#233)', function () {
    $sesi = legacySesi(officeUser('u-novi'));
    vbPost(['action' => 'briUnggah', 'sesi' => $sesi, 'data' => ['baris' => [
        ['tgl' => '2026-08-15', 'jam' => '10:00', 'nominal' => 100007, 'ket' => 'uji-dp-a'],
        ['tgl' => '2026-09-05', 'jam' => '10:01', 'nominal' => 200007, 'ket' => 'uji-dp-b'],
        ['tgl' => '2026-09-06', 'jam' => '10:02', 'nominal' => 300007, 'ket' => 'uji-dp-c'],
        ['tgl' => '2026-09-07', 'jam' => '10:03', 'nominal' => 400007, 'ket' => 'uji-dp-d'],
        ['tgl' => '2026-09-08', 'jam' => '10:04', 'nominal' => 500007, 'ket' => 'uji-dp-e1'],
        ['tgl' => '2026-09-09', 'jam' => '10:05', 'nominal' => 600007, 'ket' => 'uji-dp-e2'],
    ]]])->assertOk()->assertJsonPath('data.n', 6);

    $t = KompasState::t('bri_mutasi');
    $db = Modules::db('kompas');
    $tandai = fn (string $ket, string $dp, string $cara, int $batal = 0) => $db->update("UPDATE `$t` SET `dp_id`=?, `cara`=?, `batal_at`=? WHERE `ket`=?", [$dp, $cara, $batal, $ket]);
    expect($tandai('uji-dp-a', 'DP-A', 'cocok'))->toBe(1); // matched in another month: still held
    expect($tandai('uji-dp-b', 'DP-B', 'bukan'))->toBe(1); // a dpId with cara != cocok: still held
    expect($tandai('uji-dp-c', 'DP-C', 'cocok', 1758000000000))->toBe(1); // cancelled: holds nothing
    // uji-dp-d keeps dp_id '': a plain row holds nothing
    expect($tandai('uji-dp-e1', 'DP-E', 'cocok'))->toBe(1); // the same dpId on two rows: listed once
    expect($tandai('uji-dp-e2', 'DP-E', 'cocok'))->toBe(1);

    $want = ['DP-A', 'DP-B', 'DP-E'];

    $token = loginAs(officeUser('u-novi'));
    app('auth')->forgetGuards();
    $v1 = $this->withToken($token)->getJson('/api/v1/kompas/bri?from=2026-09-01&to=2026-09-30')
        ->assertOk()->assertJsonPath('data.total', 5)->json('data.dipakai');
    sort($v1);
    expect($v1)->toBe($want);

    $legacy = vbPost(['action' => 'briList', 'dari' => '2026-09-01', 'sampai' => '2026-09-30'])
        ->assertOk()->json('data.dipakai');
    sort($legacy);
    expect($legacy)->toBe($want);
});
