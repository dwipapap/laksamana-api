<?php

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
    $row = Modules::db('kompas')->selectOne('SELECT nominal, oleh FROM void_log WHERE id=?', [$id]);
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
    $ids = array_column(Modules::db('kompas')->select("SELECT id FROM bri_mutasi WHERE sidik LIKE '2026-09-10|15:46|300000|#%' ORDER BY sidik"), 'id');

    vbPost(['action' => 'briCocok', 'sesi' => $sesi, 'data' => ['id' => $ids[0], 'cara' => 'cocok', 'resId' => 'r1', 'dpId' => 'dp1']])->assertJsonPath('ok', true);
    vbPost(['action' => 'briCocok', 'sesi' => $sesi, 'data' => ['id' => $ids[1], 'cara' => 'cocok', 'resId' => 'r1', 'dpId' => 'dp1']])
        ->assertJsonPath('ok', false)->assertJsonPath('error', fn ($e) => str_starts_with($e, 'DP itu sudah dicocokkan'));

    vbPost(['action' => 'briUnggah', 'sesi' => $sesi, 'data' => ['baris' => [['tgl' => '2026-09-10', 'jam' => '15.46', 'nominal' => 300000, 'ket' => 'A2']]]])
        ->assertJsonPath('data.lama', 1);
    expect(Modules::db('kompas')->selectOne('SELECT ket, dp_id, cara FROM bri_mutasi WHERE id=?', [$ids[0]]))
        ->ket->toBe('A2')->dp_id->toBe('dp1')->cara->toBe('cocok');
});

it('v1: finance records and edits a void with the row version', function () {
    $token = loginAs(officeUser('u-novi'));
    $id = $this->withToken($token)->postJson('/api/v1/kompas/voids', ['tgl' => '2026-09-12', 'bill' => 'B-1', 'item' => 'Kopi', 'penginput' => 'A', 'salah' => 'B', 'alasan' => 'x', 'subtotal' => 1000])
        ->assertCreated()->json('data.id');
    $v = (int) Modules::db('kompas')->selectOne('SELECT diubah FROM void_log WHERE id=?', [$id])->diubah;
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
