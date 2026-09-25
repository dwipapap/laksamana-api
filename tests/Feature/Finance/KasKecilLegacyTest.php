<?php

use App\Support\Modules;
use Illuminate\Testing\TestResponse;

function finPost(string $action, array $body): TestResponse
{
    return test()->call('POST', '/finance-api-mysql/api.php?action='.$action, [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

it('reads getAll by default, with akses and peran as objects', function () {
    $res = $this->get('/finance-api-mysql/api.php')->assertOk()->assertJsonPath('ok', true);
    expect($res->json('data'))->toHaveKeys(['pos', 'kategori', 'trx', 'akses', 'peran'])
        ->and($res->json('data.pos.0'))->toMatchArray(['id' => 1, 'nama' => 'Kas Kecil', 'aktif' => true])
        ->and($res->json('data.trx.0.baris'))->not->toBeEmpty();
    // query action wins over the body action
    $this->call('POST', '/finance-api-mysql/api.php?action=stats', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{"action":"getAll"}')
        ->assertJsonStructure(['data' => ['pos', 'kategori', 'trx', 'baris']]);
});

it('saves a transaction with its split rows and rewrites them on edit', function () {
    $id = finPost('simpanTrx', ['tgl' => '2026-09-03', 'keterangan' => 'Galon', 'kategori_id' => 4, 'oleh' => 'Kasir',
        'baris' => [['pos_id' => 1, 'kredit' => 15000]]])->assertOk()->json('data.id');
    $db = Modules::db('finance');
    expect($db->selectOne('SELECT dibuat_oleh, kategori_id FROM kk_trx WHERE id = ?', [$id]))->dibuat_oleh->toBe('Kasir')->kategori_id->toBe(4);

    finPost('simpanTrx', ['id' => $id, 'tgl' => '2026-09-03', 'keterangan' => 'Galon', 'baris' => [['pos_id' => 2, 'debet' => 700]]])->assertOk();
    expect($db->select('SELECT pos_id, debet, kredit FROM kk_trx_pos WHERE trx_id = ?', [$id]))->toHaveCount(1)
        ->and((int) $db->selectOne('SELECT pos_id FROM kk_trx_pos WHERE trx_id = ?', [$id])->pos_id)->toBe(2);

    finPost('simpanTrx', ['tgl' => '2026-09-03', 'keterangan' => 'x', 'baris' => [['pos_id' => 1, 'debet' => 5, 'kredit' => 5]]])
        ->assertOk()->assertExactJson(['ok' => false, 'error' => 'Satu pos tidak boleh debet dan kredit sekaligus.']);
});

it('refuses to delete a used source or category and reports duplicates in words', function () {
    finPost('hapusPos', ['id' => 1])->assertJsonPath('error', 'Pos ini sudah dipakai transaksi — nonaktifkan saja.');
    finPost('simpanKategori', ['nama' => 'COGS'])->assertJsonPath('error', '"COGS" sudah ada dalam daftar.');
});

it('replaces the access matrix (clamped) and sets or clears one role', function () {
    finPost('simpanAkses', ['peta' => ['staf' => ['kk_input' => 9, 'rekap' => -1]]])
        ->assertOk()->assertExactJson(['ok' => true, 'data' => ['staf' => ['kk_input' => 2, 'rekap' => 0]]]);
    finPost('simpanPeran', ['kunci' => '#u-x', 'peran' => 'viewer'])->assertJsonPath('data.#u-x', 'viewer');
    finPost('simpanPeran', ['kunci' => '#u-x', 'peran' => ''])->assertJsonMissingPath('data.#u-x');
});

it('keeps the server sql_mode like the legacy PDO', function () {
    expect(config('database.connections.legacy_finance.strict'))->toBeNull();
});
