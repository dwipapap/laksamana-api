<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * #67: Finance served from core. Each test imports the restored finance DB and
 * switches the Modul to core; the default (legacy) connection is the rollback
 * path, covered by the rest of the suite.
 */

beforeEach(function () {
    $this->artisan('core:import', ['module' => 'finance'])->assertSuccessful();
    config(['laksamana.modules.finance.connection' => 'core']);
});

/** POST a legacy text/plain body to finance's old URL. */
function finCorePost(string $action, array $body): TestResponse
{
    return test()->call('POST', '/finance-api-mysql/api.php?action='.$action, [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

it('serves getAll from core with the legacy ids and the akses/peran objects', function () {
    $d = $this->get('/finance-api-mysql/api.php')->assertOk()->json('data');
    expect($d)->toHaveKeys(['pos', 'kategori', 'trx', 'akses', 'peran'])
        ->and($d['pos'][0])->toMatchArray(['id' => 1, 'nama' => 'Kas Kecil', 'aktif' => true])
        ->and(count($d['pos']))->toBe(DB::connection('core')->table('finance_kk_pos')->count())
        ->and(count($d['kategori']))->toBe(DB::connection('core')->table('finance_kk_kategori')->count())
        ->and(count($d['trx']))->toBe(DB::connection('core')->table('finance_kk_trx')->count())
        ->and($d['trx'][0]['baris'])->not->toBeEmpty()
        ->and($d['peran'])->toEqual(DB::connection('core')->table('finance_kk_peran')->pluck('peran', 'kunci')->all())
        ->and($d['akses']['manajemen']['kk_input'])->toBe(
            (int) DB::connection('core')->table('finance_kk_akses')->where('kunci', 'manajemen')->where('halaman', 'kk_input')->value('tingkat')
        );
});

it('writes a transaction on core: a legacy id, its split rows and the counter', function () {
    $next = (int) DB::connection('core')->table('finance_counter')->where('name', 'kk_trx')->value('next_value');
    $id = finCorePost('simpanTrx', ['tgl' => '2026-09-03', 'keterangan' => 'Core galon', 'input' => 1, 'oleh' => 'Kasir',
        'baris' => [['pos_id' => 1, 'kredit' => 15000]]])->assertOk()->json('data.id');

    expect($id)->toBe($next) // ints continue where the legacy AUTO_INCREMENT stood
        ->and(DB::connection('legacy_finance')->table('kk_trx')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::connection('core')->table('finance_kk_trx')->where('legacy_id', (string) $id)->value('keterangan'))->toBe('Core galon')
        ->and(DB::connection('core')->table('finance_kk_trx_pos')->where('trx_id', (string) $id)->count())->toBe(1)
        ->and((int) DB::connection('core')->table('finance_counter')->where('name', 'kk_trx')->value('next_value'))->toBe($next + 1);

    finCorePost('simpanTrx', ['id' => $id, 'tgl' => '2026-09-04', 'keterangan' => 'Core galon (koreksi)',
        'baris' => [['pos_id' => 2, 'debet' => 700]]])->assertOk();
    expect(DB::connection('core')->table('finance_kk_trx')->where('legacy_id', (string) $id)->value('keterangan'))->toBe('Core galon (koreksi)')
        ->and(DB::connection('core')->table('finance_kk_trx_pos')->where('trx_id', (string) $id)->value('pos_id'))->toBe('2')
        ->and((int) DB::connection('core')->table('finance_kk_trx')->where('legacy_id', (string) $id)->value('version'))->toBe(2);

    expect(finCorePost('hapusTrx', ['id' => $id])->assertOk()->json('data.dihapus'))->toBe(1)
        ->and(DB::connection('core')->table('finance_kk_trx')->where('legacy_id', (string) $id)->exists())->toBeFalse()
        ->and(DB::connection('core')->table('finance_kk_trx_pos')->where('trx_id', (string) $id)->exists())->toBeFalse();
});

it('truncates an over-long keterangan on strict core like non-strict production (#97)', function () {
    $long = str_repeat('K', 300);
    $id = finCorePost('simpanTrx', ['tgl' => '2026-09-03', 'keterangan' => $long, 'input' => 1, 'oleh' => 'Kasir',
        'baris' => [['pos_id' => 1, 'kredit' => 1000]]])->assertOk()->json('data.id');

    expect(DB::connection('core')->table('finance_kk_trx')->where('legacy_id', (string) $id)->value('keterangan'))
        ->toBe(str_repeat('K', 255));
});

it('serves the invoice queue from core: request, issue, print', function () {
    $req = finCorePost('invMinta', ['resId' => 'rm-core-1', 'oleh' => 'Host', 'ringkas' => ['nama' => 'Core']])->assertOk()->json('data');
    expect($req['id'])->toStartWith('inv')->and($req['status'])->toBe('MENUNGGU')
        ->and(DB::connection('core')->table('finance_inv_kwitansi')->where('legacy_id', $req['id'])->exists())->toBeTrue();

    // the default signatory comes from finance_inv_setting, the snapshot from finance_inv_penanda
    $issued = finCorePost('invPutus', ['id' => $req['id'], 'aksi' => 'buat', 'oleh' => 'Fin'])->assertOk()->json('data');
    expect($issued['status'])->toBe('DIBUAT')->and($issued['no'])->toMatch('#^INV/\d{4}/\d{2}/\d{4}$#')
        ->and($issued['penanda'])->toBe([['id' => 'pen6a87ce77dehvuk', 'nama' => 'Cindy Juliawati', 'jabatan' => 'Finance']])
        ->and((int) DB::connection('core')->table('finance_inv_kwitansi')->where('legacy_id', $req['id'])->value('version'))->toBe(2);

    $file = finCorePost('invBerkas', ['resId' => 'rm-core-1'])->assertOk()->json('data');
    expect($file['ada'])->toBeTrue()->and($file['no'])->toBe($issued['no'])
        ->and($file['penanda'][0]['ttd'])->not->toBeEmpty();

    expect(finCorePost('invAntre', [])->assertOk()->json('data.total'))->toBe(
        DB::connection('core')->table('finance_inv_kwitansi')->where('status', 'MENUNGGU')->count()
    );
});

it('serves the vault document, its roles and the v1 writes from core', function () {
    $before = finCorePost('brankasGet', [])->assertOk()->json('data');
    expect($before)->toHaveKeys(['data', 'akses', 'peran', 'updated_at'])
        ->and(DB::connection('core')->table('finance_bk_state')->where('legacy_id', '1')->exists())->toBeTrue()
        ->and($before['data']['mutasi'])->not->toBeEmpty(); // imported, not re-derived

    finCorePost('brankasSave', ['oleh' => '  CFO Core  ', 'data' => ['mutasi' => $before['data']['mutasi'], 'investor' => [['id' => 'i1']], 'foo' => 1]])->assertOk();
    $after = finCorePost('brankasGet', [])->assertOk()->json('data');
    expect($after['data']['investor'])->toBe([['id' => 'i1']])->and($after['data']['mutasi'])->toBe($before['data']['mutasi'])
        ->and(DB::connection('core')->table('finance_bk_state')->where('legacy_id', '1')->value('diubah_oleh'))->toBe('CFO Core')
        ->and($after['updated_at'])->toBeGreaterThan(0);

    // the controller wraps the map: {peran: {'#<user id>': role}}
    finCorePost('brankasPeran', ['kunci' => '#u-cindy', 'peran' => 'cfo'])->assertOk()->assertJsonPath('data.peran.#u-cindy', 'cfo');
    $peran = DB::connection('core')->table('finance_bk_peran')->where('kunci', '#u-cindy')->first();
    expect($peran->peran)->toBe('cfo')
        ->and($peran->user_id)->toBe(DB::connection('core')->table('user')->where('legacy_id', 'u-cindy')->value('id'));

    // the v1 vault writes the same document, recording the session user
    $token = loginAs(officeUser('u-dwipa'));
    $v = $this->withToken($token)->getJson('/api/v1/finance/vault')->assertOk()->json('meta.version');
    $this->withToken($token)->postJson("/api/v1/finance/vault/investor?version=$v", ['nama' => 'Inv Core'])
        ->assertCreated()->assertJsonPath('data.nama', 'Inv Core');
    expect(DB::connection('core')->table('finance_bk_state')->where('legacy_id', '1')->value('diubah_oleh'))->toBe(officeUser('u-dwipa')['name']);
});

it('keeps the v1 petty-cash writes on core', function () {
    $token = loginAs(officeUser('u-novi'));
    $state = $this->withToken($token)->getJson('/api/v1/finance/petty-cash')->assertOk();
    expect($state->json('data.pos.0.id'))->toBe(1)
        ->and($state->json('data.trx'))->toHaveCount(DB::connection('core')->table('finance_kk_trx')->count());

    $res = $this->withToken($token)->postJson('/api/v1/finance/petty-cash/transactions',
        ['tgl' => '2026-09-04', 'keterangan' => 'Core token listrik', 'baris' => [['pos_id' => 1, 'kredit' => 50000]]])->assertCreated();
    $id = $res->json('data.id');
    expect(DB::connection('core')->table('finance_kk_trx')->where('legacy_id', (string) $id)->value('dibuat_oleh'))->toBe(officeUser('u-novi')['name'])
        ->and((int) DB::connection('core')->table('finance_kk_trx_pos')->where('trx_id', (string) $id)->sum('kredit'))->toBe(50000);

    $v = $res->json('meta.version');
    $this->withToken($token)->patchJson("/api/v1/finance/petty-cash/transactions/$id?version=stale", ['bon' => true])->assertStatus(409);
    $this->withToken($token)->patchJson("/api/v1/finance/petty-cash/transactions/$id?version=$v", ['bon' => true])->assertOk()->assertJsonPath('data.bon', 1);
    expect((int) DB::connection('core')->table('finance_kk_trx')->where('legacy_id', (string) $id)->value('bon'))->toBe(1);
});

it('lets the module admin replace the Akses Halaman matrix on core', function () {
    $admin = loginAs(officeUser('u-wandi'));
    $m = $this->withToken($admin)->getJson('/api/v1/finance/petty-cash/access')->assertOk()->json('meta.version');
    $this->withToken($admin)->putJson("/api/v1/finance/petty-cash/access/matrix?version=$m", ['matrix' => ['viewer' => ['rekap' => 1]]])
        ->assertOk()->assertJsonPath('data.viewer.rekap', 1);
    expect(DB::connection('core')->table('finance_kk_akses')->pluck('kunci')->unique()->values()->all())->toBe(['viewer'])
        ->and((int) DB::connection('core')->table('finance_kk_akses')->where('kunci', 'viewer')->value('tingkat'))->toBe(1)
        ->and(DB::connection('legacy_finance')->table('kk_akses')->count())->toBeGreaterThan(0); // legacy rows untouched
});

it('keeps serving Reservasi requests (the cross-module entry point) from core', function () {
    $token = loginAs(officeUser('u-andry')); // holds reservasi, not finance
    $this->withToken($token)->postJson('/api/v1/finance/invoices/requests', ['resId' => 'rm-core-x', 'oleh' => 'spoof', 'ringkas' => ['nama' => 'Core']])
        ->assertOk()->assertJsonPath('data.status', 'MENUNGGU')->assertJsonPath('data.mintaOleh', officeUser('u-andry')['name']);
    $row = DB::connection('core')->table('finance_inv_kwitansi')->where('res_id', 'rm-core-x')->first();
    expect($row)->not->toBeNull()->and($row->minta_oleh)->toBe(officeUser('u-andry')['name']);

    $this->withToken($token)->getJson('/api/v1/finance/invoices')->assertStatus(403); // the queue stays Finance's
});
