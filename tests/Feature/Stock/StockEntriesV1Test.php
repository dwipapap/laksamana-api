<?php

use App\Support\Modules;

/*
 * #35 v1. u-adit: ordering + usage, Kitchen crew. u-arif: ordering + usage, Bar.
 * u-andry: ordering + purchasing (no usage, not admin). u-wandi: admin of everything.
 */

it('keeps the Usage Panel records to the usage Modul', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/stock/waste')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('creates, reads, patches (keeping the photo) and deletes a waste record with versions', function () {
    $token = loginAs(officeUser('u-adit'));
    $c = $this->withToken($token)->postJson('/api/v1/stock/waste', ['tanggal' => '2031-01-01', 'item' => 'Roti', 'qty' => 2, 'tim' => 'Kitchen',
        'pic' => 'Someone Else', 'foto' => 'data:x', 'fotoNama' => 'a.png'])
        ->assertCreated()->assertJsonPath('data.adaFoto', true)->assertJsonPath('data.pic', officeUser('u-adit')['name']);
    $id = $c->json('data.id');
    $this->withToken($token)->postJson('/api/v1/stock/waste', ['tanggal' => '2031-01-01', 'item' => 'Roti'])
        ->assertStatus(422)->assertJsonPath('error.message', 'jumlah harus lebih dari 0');

    $this->withToken($token)->patchJson("/api/v1/stock/waste/$id", ['qty' => 3])->assertStatus(428);
    $p = $this->withToken($token)->withHeader('If-Match', $c->json('meta.version'))->patchJson("/api/v1/stock/waste/$id", ['qty' => 3])
        ->assertOk()->assertJsonPath('data.qty', 3)->assertJsonPath('data.item', 'Roti')->assertJsonPath('data.adaFoto', true);
    $this->flushHeaders();
    $this->withToken($token)->getJson("/api/v1/stock/waste/$id/photo")->assertOk()->assertJsonPath('data.foto', 'data:x');
    $this->withToken($token)->withHeader('If-Match', $c->json('meta.version'))->deleteJson("/api/v1/stock/waste/$id")
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');
    $this->flushHeaders();
    $this->withToken($token)->withHeader('If-Match', $p->json('meta.version'))->deleteJson("/api/v1/stock/waste/$id")->assertOk();
    expect(Modules::db('stock')->selectOne('SELECT id FROM waste WHERE id = ?', [$id]))->toBeNull();
});

it('edits a handover without the legacy 500', function () {
    $token = loginAs(officeUser('u-arif'));
    $c = $this->withToken($token)->postJson('/api/v1/stock/handovers', ['tanggal' => '2031-01-01', 'tujuan' => 'Kitchen', 'tim' => 'Bar',
        'items' => [['item' => 'Gula', 'qty' => 1]], 'foto' => 'data:y'])->assertCreated();
    $this->withToken($token)->withHeader('If-Match', $c->json('meta.version'))
        ->patchJson('/api/v1/stock/handovers/'.$c->json('data.id'), ['penerima' => 'Budi'])
        ->assertOk()->assertJsonPath('data.penerima', 'Budi')->assertJsonPath('data.items.0.item', 'Gula');
});

it('scopes lists and single reads to the caller\'s team when the switch is on', function () {
    config(['laksamana.stock_batas_per_tim' => true]);
    Modules::db('stock')->insert("INSERT INTO usage_events (id,tanggal,jenis,tim,waktu,data) VALUES
        ('USE-T1','2031-02-01','Event','Kitchen','','{}'), ('USE-T2','2031-02-01','Event','Bar','','{}')");
    $token = loginAs(officeUser('u-adit'));
    $ids = array_column($this->withToken($token)->getJson('/api/v1/stock/usage?from=2031-02-01')->assertOk()->json('data'), 'id');
    expect($ids)->toBe(['USE-T1']);
    $this->withToken($token)->getJson('/api/v1/stock/usage/USE-T2')->assertNotFound();
});

it('runs an opname through create and patch, keeping unfilled numbers null', function () {
    $token = loginAs(officeUser('u-adit'));
    $c = $this->withToken($token)->postJson('/api/v1/stock/opname', ['tanggal' => '2031-01-01', 'items' => [['item' => 'Gula', 'sistem' => 2, 'fisik' => '']]])
        ->assertCreated()->assertJsonPath('data.status', 'Draft')->assertJsonPath('data.items.0.fisik', null);
    $this->withToken($token)->withHeader('If-Match', $c->json('meta.version'))
        ->patchJson('/api/v1/stock/opname/'.$c->json('data.id'), ['status' => 'Selesai'])
        ->assertOk()->assertJsonPath('data.status', 'Selesai')->assertJsonPath('data.items.0.sistem', 2);
});

it('reads the CK balance and movements, and records an outlet delivery', function () {
    $token = loginAs(officeUser('u-adit'));
    $this->withToken($token)->getJson('/api/v1/stock/ck/balance')->assertOk()->assertJsonStructure(['data' => [['item', 'saldo']]]);
    $this->withToken($token)->getJson('/api/v1/stock/ck/movements?from=2099-01-01')->assertOk()->assertJsonPath('meta.total', 0);
    $this->withToken($token)->postJson('/api/v1/stock/ck/deliveries', ['item' => 'Dimsum Ayam', 'qtyInput' => 1, 'unitInput' => 'Pack', 'tanggal' => '2031-01-01'])
        ->assertCreated()->assertJsonPath('data.qty', 9)->assertJsonPath('data.sebab', 'kiriman');
    $this->withToken($token)->postJson('/api/v1/stock/ck/movements', ['item' => 'Ayam Hainan'])->assertStatus(403); // manual: Purchasing
});

it('lets Purchasing edit a manual CK movement but never an order-sync row', function () {
    $token = loginAs(officeUser('u-andry'));
    $c = $this->withToken($token)->postJson('/api/v1/stock/ck/movements', ['item' => 'Ayam Hainan', 'arah' => 'masuk', 'qtyInput' => 1, 'unitInput' => 'Pack'])
        ->assertCreated()->assertJsonPath('data.qty', 1200);
    $this->withToken($token)->withHeader('If-Match', $c->json('meta.version'))
        ->patchJson('/api/v1/stock/ck/movements/'.$c->json('data.id'), ['qtyInput' => 2])->assertOk()->assertJsonPath('data.qty', 2400);
    $this->flushHeaders();

    $ref = $this->withToken($token)->getJson('/api/v1/stock/ck/movements/'.Modules::db('stock')->selectOne('SELECT id FROM ck_stock WHERE ref IS NOT NULL LIMIT 1')->id)->assertOk();
    $this->withToken($token)->withHeader('If-Match', $ref->json('meta.version'))->deleteJson('/api/v1/stock/ck/movements/'.$ref->json('data.id'))
        ->assertStatus(422)->assertJsonPath('error.message', 'mutasi dari pengajuan hanya hilang bila check-in dibatalkan');
});

it('logs with the acting user as aktor', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/stock/logs', ['entries' => [['modul' => 'purchasing', 'aksi' => 'tes_v1', 'aktor' => 'Palsu']]])
        ->assertCreated()->assertJsonPath('data.recorded', 1);
    expect(Modules::db('stock')->selectOne("SELECT aktor FROM activity_log WHERE aksi = 'tes_v1'")->aktor)->toBe(officeUser('u-andry')['name']);
});

it('refuses the log to non-admins', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->getJson('/api/v1/stock/logs')->assertStatus(403);
});

it('serves the log to a Purchasing admin', function () {
    $this->withToken(loginAs(officeUser('u-wandi')))->getJson('/api/v1/stock/logs?modul=ordering&limit=3')->assertOk()->assertJsonCount(3, 'data');
});
