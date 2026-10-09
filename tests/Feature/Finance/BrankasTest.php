<?php

require_once __DIR__.'/helpers.php';

use App\Auth\AccountRepository;
use App\Auth\OfficeAccess;
use App\Modules\Finance\Services\Brankas;
use App\Support\Modules;
use Illuminate\Testing\TestResponse;

/* Legacy compat + v1 for Panel Brankas. u-dwipa holds `brankas` and `finance`; u-novi only `finance`; u-wandi is superadmin. */

function bkPost(string $action, array $body): TestResponse
{
    return test()->call('POST', '/finance-api-mysql/api.php?action='.$action, [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

function bkState(): array
{
    return json_decode(Modules::db('finance')->selectOne(finSql('SELECT data FROM bk_state WHERE id=1'))->data, true);
}

/** A throwaway active user holding exactly the given modules. */
function wbUser(string $id, array $modules): array
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
 * Token for a user, dropping the guard's cached user: this file switches
 * identity inside one test, and the guard would otherwise keep answering as
 * whoever made the previous request.
 */
function wbToken(array $u): string
{
    $token = loginAs($u);
    app('auth')->forgetGuards();

    return $token;
}

it('brankasSave keeps only the known keys and bayarSave replaces only bayar', function () {
    $before = bkState();
    bkPost('brankasSave', ['oleh' => 'CFO', 'data' => ['mutasi' => $before['mutasi'], 'rekening' => ['k' => ['id' => 'r1']], 'foo' => 1]])
        ->assertOk()->assertJsonPath('data.saved', true);
    $s = bkState();
    expect(array_keys($s))->toBe(['rekening', 'piutang', 'bayar', 'investor', 'mutasi', 'setting'])
        ->and($s['rekening'])->toBe([['id' => 'r1']])
        ->and($s['mutasi'])->toBe($before['mutasi']);

    bkPost('bayarSave', ['oleh' => 'Kasir', 'bayar' => [['id' => 'b1', 'status' => 'paid']]])->assertOk();
    $s2 = bkState();
    expect($s2['bayar'])->toBe([['id' => 'b1', 'status' => 'paid']])->and($s2['mutasi'])->toBe($before['mutasi'])
        ->and(Modules::db('finance')->selectOne(finSql('SELECT updated_by FROM bk_state'))->updated_by)->toBe('Kasir');

    bkPost('brankasSave', ['oleh' => 'x'])->assertJsonPath('error', 'data brankas kosong');
    bkPost('bayarSave', [])->assertJsonPath('error', 'daftar pembayaran kosong');
});

it('brankasGet returns the complete shape, access matrix and roles', function () {
    $this->get('/finance-api-mysql/api.php?action=brankasGet')->assertOk()
        ->assertJsonStructure(['data' => ['data' => ['rekening', 'piutang', 'bayar', 'investor', 'mutasi', 'setting'], 'akses', 'peran', 'updated_at']]);
    bkPost('brankasAkses', ['peta' => ['cfo' => ['saldo' => 9]]])->assertExactJson(['ok' => true, 'data' => ['akses' => ['cfo' => ['saldo' => 2]]]]);
});

it('gates the vault by the brankas module', function () {
    $this->withToken(loginAs(officeUser('u-novi')))->getJson('/api/v1/finance/vault')->assertStatus(403);
});

it('adds, edits and deletes a vault record with the blob version, as the session user', function () {
    $token = loginAs(officeUser('u-dwipa'));
    $v = $this->withToken($token)->getJson('/api/v1/finance/vault')->assertOk()->json('meta.version');

    $this->withToken($token)->postJson('/api/v1/finance/vault/investor', ['nama' => 'Inv API'])->assertStatus(428);
    $this->withToken($token)->postJson('/api/v1/finance/vault/investor?version=1', ['nama' => 'Inv API'])->assertStatus(409);
    $res = $this->withToken($token)->postJson("/api/v1/finance/vault/investor?version=$v", ['nama' => 'Inv API', 'modal' => 5])
        ->assertCreated()->assertJsonPath('data.nama', 'Inv API');
    $id = $res->json('data.id');
    $v2 = $res->json('meta.version');
    expect($v2)->toBeGreaterThan($v)
        ->and(Modules::db('finance')->selectOne(finSql('SELECT updated_by FROM bk_state'))->updated_by)->toBe(officeUser('u-dwipa')['name']);

    $v3 = $this->withToken($token)->patchJson("/api/v1/finance/vault/investor/$id?version=$v2", ['modal' => 7])
        ->assertOk()->assertJsonPath('data.modal', 7)->assertJsonPath('data.nama', 'Inv API')->json('meta.version');
    $this->withToken($token)->deleteJson("/api/v1/finance/vault/investor/$id?version=$v3")->assertOk();
    expect(collect(bkState()['investor'])->pluck('id'))->not->toContain($id)
        ->and(bkState()['mutasi'])->not->toBeEmpty(); // the rest of the blob untouched
});

it('lets finance users replace the Kas Kecil payment plan without touching the vault', function () {
    $token = loginAs(officeUser('u-novi'));
    $plan = $this->withToken($token)->getJson('/api/v1/finance/petty-cash/payment-plan')->assertOk();
    // The payment plan GET returns `data` as a plain list of rows, never {bayar: [...]}.
    expect($plan->json('data'))->toBeArray()->and(array_is_list($plan->json('data')))->toBeTrue();
    $mutasi = bkState()['mutasi'];
    $this->withToken($token)->putJson('/api/v1/finance/petty-cash/payment-plan?version='.$plan->json('meta.version'), ['bayar' => [['id' => 'p1', 'status' => 'plan']]])
        ->assertOk()->assertJsonPath('data.0.id', 'p1');
    expect(bkState()['mutasi'])->toBe($mutasi);
});

it('serves vault-side wallet balances to finance holders without the vault lists', function () {
    app(Brankas::class)->save([
        'rekening' => [],
        'piutang' => [['id' => 'p1', 'nominal' => 999999]],
        'bayar' => [
            ['id' => 'b1', 'status' => 'paid', 'dari' => 'bri', 'amount' => 100000],
            ['id' => 'b2', 'status' => 'scheduled', 'dari' => 'bri', 'amount' => 50000],
            ['id' => 'b3', 'status' => 'paid', 'dari' => 'dompet-lain', 'amount' => 70000],
        ],
        'investor' => [
            ['id' => 'i1', 'returns' => [['dari' => 'mandiri', 'amount' => 20000]], 'tambahan' => [['ke' => 'bca', 'amount' => 300000]]],
        ],
        'mutasi' => [
            ['id' => 'm1', 'jenis' => 'pindah', 'dari' => 'bri', 'ke' => 'cash', 'nominal' => 50000],
            ['id' => 'm2', 'jenis' => 'masuk', 'ke' => 'uob', 'nominal' => 15000],
            ['id' => 'm3', 'jenis' => 'keluar', 'dari' => 'cash', 'nominal' => 5000],
        ],
        'setting' => ['awal' => ['bri' => 1000000, 'cash' => 200000], 'peta' => ['qr_order' => 'bri']],
    ], 'CFO');

    $token = wbToken(wbUser('u-wb-fin', ['finance']));
    $res = $this->withToken($token)->getJson('/api/v1/finance/petty-cash/wallet-balances')->assertOk();

    // The endpoint never recomputes: it answers what the shared vault service says.
    expect($res->json('data'))->toBe(app(Brankas::class)->walletBalances())
        ->and($res->json('data.wallets'))->toBe([
            ['wallet' => 'bri', 'nama' => 'BRI', 'saldo' => 850000],
            ['wallet' => 'mandiri', 'nama' => 'Mandiri', 'saldo' => -20000],
            ['wallet' => 'bca', 'nama' => 'BCA', 'saldo' => 300000],
            ['wallet' => 'uob', 'nama' => 'UOB', 'saldo' => 15000],
            ['wallet' => 'cash', 'nama' => 'Cash / Brankas Fisik', 'saldo' => 245000],
        ])
        ->and($res->json('data.total'))->toBe(1390000)
        ->and($res->json('data.peta'))->toBe(['qr_order' => 'bri'])
        ->and($res->json('meta.version'))->toBe(app(Brankas::class)->read()['updated_at']);
    // Balances only: none of the vault lists leak through.
    expect(array_keys($res->json('data')))->toBe(['wallets', 'total', 'peta']);

    // A holder of neither module is refused.
    $this->withToken(wbToken(wbUser('u-wb-none', ['marketing'])))->getJson('/api/v1/finance/petty-cash/wallet-balances')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});
