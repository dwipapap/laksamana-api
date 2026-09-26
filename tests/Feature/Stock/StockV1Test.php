<?php

use App\Modules\Stock\Services\StockSupport;
use App\Support\Modules;

/*
 * u-andry: ordering + purchasing. u-adit: ordering (not purchasing).
 * u-jb: purchasing + hpp. u-aldialfayat: no stock module.
 */

it('requires login and one of the stock modules', function () {
    $this->getJson('/api/v1/stock/products')->assertStatus(401);
    $this->withToken(loginAs(officeUser('u-aldialfayat')))->getJson('/api/v1/stock/products')
        ->assertStatus(403)->assertJsonPath('error.code', 'module_not_granted');
});

it('lists products with versions and reads one by name', function () {
    $token = loginAs(officeUser('u-adit'));
    $res = $this->withToken($token)->getJson('/api/v1/stock/products')->assertOk();
    expect($res->json('meta.total'))->toBe((int) Modules::db('stock')->selectOne(StockSupport::q('SELECT COUNT(*) c FROM {products}'))->c);
    $nama = $res->json('data.0.nama');
    $this->withToken($token)->getJson('/api/v1/stock/products/'.rawurlencode($nama))->assertOk()
        ->assertJsonPath('data.nama', $nama)->assertJsonPath('meta.version', $res->json("meta.versions.$nama"));
});

it('keeps the vendor database to Purchasing', function () {
    $this->withToken(loginAs(officeUser('u-adit')))->postJson('/api/v1/stock/vendors', ['nama' => 'V1 Vendor'])
        ->assertStatus(403);
});

it('creates, patches (preserve-if-null), renames and deletes a product with versions', function () {
    $token = loginAs(officeUser('u-jb'));
    $c = $this->withToken($token)->postJson('/api/v1/stock/products', ['nama' => 'V1 Tepung', 'utama' => 'A', 'satuan' => ['Kg'], 'kategori' => 'DRY'])
        ->assertCreated()->assertJsonPath('data.kategori', 'DRY')->assertJsonPath('meta.report.hppBaru', true);
    $this->withToken($token)->postJson('/api/v1/stock/products', ['nama' => 'v1 tepung'])->assertStatus(409);

    $this->withToken($token)->patchJson('/api/v1/stock/products/V1 Tepung', ['utama' => 'B'])->assertStatus(428);
    $p = $this->withToken($token)->withHeader('If-Match', $c->json('meta.version'))
        ->patchJson('/api/v1/stock/products/V1 Tepung', ['utama' => 'B', 'nama' => 'V1 Tepung Baru'])->assertOk()
        ->assertJsonPath('data.nama', 'V1 Tepung Baru')->assertJsonPath('data.kategori', 'DRY')->assertJsonPath('data.utama', 'B');
    $this->flushHeaders();
    $this->withToken($token)->withHeader('If-Match', 'stale')->deleteJson('/api/v1/stock/products/V1 Tepung Baru')->assertStatus(409);
    $this->flushHeaders();
    $this->withToken($token)->withHeader('If-Match', $p->json('meta.version'))->deleteJson('/api/v1/stock/products/V1 Tepung Baru')->assertOk();
    expect(Modules::db('stock')->selectOne(StockSupport::q("SELECT nama FROM {products} WHERE nama IN ('V1 Tepung','V1 Tepung Baru')")))->toBeNull();
});

it('submits a batch with the acting user as pic, then patches and deletes an order', function () {
    $token = loginAs(officeUser('u-adit'));
    $res = $this->withToken($token)->postJson('/api/v1/stock/orders/batches', ['tim' => 'Kitchen', 'orders' => [
        ['item' => 'V1 Bawang', 'qty' => 2, 'unit' => 'Kg', 'tglDatang' => '2031-02-02', 'pic' => 'Someone Else'],
    ]])->assertCreated();
    $nomor = $res->json('data.orders.0.nomorOrder');
    $name = officeUser('u-adit')['name'];
    expect($res->json('data.orders.0.pic'))->toBe($name);

    $o = $this->withToken($token)->getJson('/api/v1/stock/orders/'.$nomor)->assertOk();
    $this->withToken($token)->withHeader('If-Match', $o->json('meta.version'))
        ->patchJson('/api/v1/stock/orders/'.$nomor, ['qty' => 5, 'kedatangan' => 'Datang', 'catatanAktual' => '5', 'tglJemput' => '2031-02-03'])
        ->assertOk()->assertJsonPath('data.qty', 5)->assertJsonPath('data.kedatangan', 'Datang')->assertJsonPath('data.tglJemput', '2031-02-03')
        ->assertJsonPath('meta.ck.disinkron', 0);
    $this->flushHeaders();
    $this->withToken($token)->withHeader('If-Match', $o->json('meta.version'))->deleteJson('/api/v1/stock/orders/'.$nomor)
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');
    $this->flushHeaders();
    $this->withToken($token)->withHeader('If-Match', $o->json('meta.version'))
        ->patchJson('/api/v1/stock/orders/'.$nomor, ['tglJemput' => 'besok'])->assertStatus(409);
});

it('filters orders and lists joinable batches', function () {
    $token = loginAs(officeUser('u-andry'));
    $d = $this->withToken($token)->getJson('/api/v1/stock/orders?tim=Kitchen&status=Aktif')->assertOk()->json('data');
    foreach ($d as $o) {
        expect($o['tim'])->toBe('Kitchen')->and($o['status'])->toBe('Aktif');
    }
    $this->withToken($token)->getJson('/api/v1/stock/orders/batches?tim=Kitchen')->assertOk();
    $this->withToken($token)->getJson('/api/v1/stock/orders/stats')->assertOk()->assertJsonStructure(['data' => ['orders', 'orders_aktif']]);
});

it('refuses archive to Ordering-only crew', function () {
    $nomor = Modules::db('stock')->selectOne(StockSupport::q("SELECT nomor_order FROM {orders} WHERE status = 'Aktif' LIMIT 1"))->nomor_order;
    $this->withToken(loginAs(officeUser('u-adit')))->postJson('/api/v1/stock/orders/archive', ['orderIds' => [$nomor]])->assertStatus(403);
});

it('archives and restores orders for Purchasing', function () {
    $nomor = Modules::db('stock')->selectOne(StockSupport::q("SELECT nomor_order FROM {orders} WHERE status = 'Aktif' LIMIT 1"))->nomor_order;
    $token = loginAs(officeUser('u-andry'));
    $this->withToken($token)->postJson('/api/v1/stock/orders/archive', ['orderIds' => [$nomor]])->assertOk()->assertJsonPath('data.updated', 1);
    $this->withToken($token)->postJson('/api/v1/stock/orders/unarchive', ['orderIds' => [$nomor]])->assertOk()->assertJsonPath('data.updated', 1);
});

it('replaces Stock Today with a version and refuses an empty snapshot', function () {
    $token = loginAs(officeUser('u-adit'));
    $v = $this->withToken($token)->getJson('/api/v1/stock/stock-today')->assertOk()->json('meta.version');
    $this->withToken($token)->withHeader('If-Match', $v)->putJson('/api/v1/stock/stock-today', ['as_of' => 'x', 'stock' => new stdClass])
        ->assertStatus(422)->assertJsonPath('error.message', 'stock kosong');
    $this->withToken($token)->withHeader('If-Match', $v)->putJson('/api/v1/stock/stock-today', ['as_of' => '2031-01-01', 'stock' => ['Gula' => ['stock_now' => 3, 'stock_unit' => 'Kg']]])
        ->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.stock.Gula.stock_now', 3);
});
