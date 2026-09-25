<?php

use App\Support\Modules;
use Illuminate\Testing\TestResponse;

/* u-andry holds reservasi (not finance); u-novi holds finance; u-adit none of them. */

function invPost(string $action, mixed $body): TestResponse
{
    return test()->call('POST', '/finance-api-mysql/api.php?action='.$action, [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($body));
}

it('legacy: a request is idempotent per reservation and an issued one stays untouched', function () {
    $a = invPost('invMinta', ['resId' => 'rm-test-1', 'oleh' => 'Host', 'ringkas' => ['nama' => 'A']])->assertOk()->json('data');
    $b = invPost('invMinta', ['resId' => 'rm-test-1', 'oleh' => 'Host', 'jenis' => 'inv_lunas'])->json('data');
    expect($b['id'])->toBe($a['id'])->and($b['jenis'])->toBe('INV_LUNAS')->and($b['ringkas'])->toBe(['nama' => 'A'])
        ->and($a['id'])->toStartWith('inv');

    $issued = invPost('invMinta', ['resId' => 'rmt1j99zo5og3', 'ringkas' => ['nama' => 'X']])->json('data');
    expect($issued['status'])->toBe('DIBUAT')->and($issued['ringkas']['nama'])->toBe('ANDI');
});

it('legacy: issuing numbers once per month pool; tolak needs a note; batal keeps the number', function () {
    $row = invPost('invPutus', ['id' => 'inv6a941cdf2jqrx9', 'aksi' => 'buat', 'oleh' => 'Fin'])->assertOk()->json('data');
    expect($row['no'])->toMatch('#^INV/\d{4}/\d{2}/\d{4}$#')->and($row['status'])->toBe('DIBUAT')
        ->and($row['penanda'])->toBe([['id' => 'pen6a87ce77dehvuk', 'nama' => 'Cindy Juliawati', 'jabatan' => 'Finance']]);
    expect(invPost('invPutus', ['id' => 'inv6a941cdf2jqrx9', 'aksi' => 'buat'])->json('data.no'))->toBe($row['no']);
    expect(invPost('invPutus', ['id' => 'inv6a941cdf2jqrx9', 'aksi' => 'batal'])->json('data'))->toMatchArray(['status' => 'MENUNGGU', 'no' => $row['no']]);
    invPost('invPutus', ['id' => 'inv6a941cdf2jqrx9', 'aksi' => 'tolak'])->assertJsonPath('error', 'alasan penolakan wajib diisi');
});

it('legacy: a signatory on an issued document cannot be deleted; renaming keeps the image', function () {
    invPost('invPenandaHapus', ['penandaId' => 'pen6a87ce77dehvuk'])->assertJsonPath('ok', false);
    $len = strlen(Modules::db('finance')->selectOne("SELECT ttd FROM inv_penanda WHERE id='pen6a87fb6c82l54u'")->ttd);
    invPost('invPenandaSimpan', ['data' => ['id' => 'pen6a87fb6c82l54u', 'nama' => 'Howandi', 'jabatan' => 'Direktur']])->assertOk();
    expect(strlen(Modules::db('finance')->selectOne("SELECT ttd FROM inv_penanda WHERE id='pen6a87fb6c82l54u'")->ttd))->toBe($len);
});

it('v1: a reservasi user requests a kwitansi as themselves and reads its status', function () {
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->postJson('/api/v1/finance/invoices/requests', ['resId' => 'rm-v1-1', 'oleh' => 'spoof', 'ringkas' => ['nama' => 'B']])
        ->assertOk()->assertJsonPath('data.mintaOleh', officeUser('u-andry')['name'])->assertJsonPath('data.status', 'MENUNGGU');
    $this->withToken($token)->getJson('/api/v1/finance/invoices/status?res=rm-v1-1,rmt1j99zo5og3')
        ->assertOk()->assertJsonPath('data.rmt1j99zo5og3.status', 'DIBUAT')->assertJsonPath('data.rm-v1-1.status', 'MENUNGGU');
    $this->withToken($token)->getJson('/api/v1/finance/invoices/file/rmt1j99zo5og3')->assertOk()->assertJsonPath('data.ada', true);
    $this->withToken($token)->getJson('/api/v1/finance/invoices/file/rm-v1-1')->assertStatus(404);
    $this->withToken($token)->getJson('/api/v1/finance/invoices')->assertStatus(403); // the queue is Finance's
});

it('v1: callers without finance, reservasi or marketing are refused', function () {
    $this->withToken(loginAs(officeUser('u-adit')))->postJson('/api/v1/finance/invoices/requests', ['resId' => 'x'])->assertStatus(403);
});

it('v1: Finance decides with the request version, as the session user', function () {
    $token = loginAs(officeUser('u-novi'));
    $list = $this->withToken($token)->getJson('/api/v1/finance/invoices?status=DITOLAK')->assertOk();
    $v = $list->json('meta.versions.inv6a97ac82ronavh');

    $this->withToken($token)->postJson('/api/v1/finance/invoices/inv6a97ac82ronavh/decision', ['aksi' => 'buat'])->assertStatus(428);
    $this->withToken($token)->postJson('/api/v1/finance/invoices/inv6a97ac82ronavh/decision?version=stale', ['aksi' => 'buat'])->assertStatus(409);
    $this->withToken($token)->postJson("/api/v1/finance/invoices/inv6a97ac82ronavh/decision?version=$v", ['aksi' => 'buat', 'penanda' => [], 'oleh' => 'spoof'])
        ->assertOk()->assertJsonPath('data.status', 'DIBUAT')->assertJsonPath('data.penanda', [])
        ->assertJsonPath('data.putusOleh', officeUser('u-novi')['name']);
});
