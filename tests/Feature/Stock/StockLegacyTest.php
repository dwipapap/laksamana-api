<?php

use App\Modules\Stock\Services\StockTeamScope;
use App\Support\Modules;
use Illuminate\Testing\TestResponse;

function stPost(string $file, mixed $body): TestResponse
{
    return test()->call('POST', '/stock-api-mysql/'.$file, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=utf-8'],
        is_string($body) ? $body : json_encode($body));
}

function stDb()
{
    return Modules::db('stock');
}

it('answers ping before the token check, with the raw envelope', function () {
    config(['laksamana.legacy_api_token' => 'rahasia']);
    $this->get('/stock-api-mysql/orders.php?action=ping')->assertOk()
        ->assertJsonPath('status', 'success')->assertJsonPath('ok', true)->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/stock-api-mysql/items.php')->assertStatus(403)->assertExactJson(['status' => 'error', 'message' => 'token salah']);
    stPost('items.php?token=rahasia', ['action' => 'nope', 'token' => 'x'])->assertStatus(400); // ?token= wins
});

it('uses real HTTP codes: 400 bad JSON / unknown action, 405 method', function () {
    stPost('vendors.php', '[1,2]')->assertStatus(400)->assertExactJson(['status' => 'error', 'message' => 'body bukan JSON']);
    stPost('orders.php', ['action' => 'zap'])->assertStatus(400)->assertJsonPath('message', 'action tidak dikenal: zap');
    $this->put('/stock-api-mysql/stock.php')->assertStatus(405)->assertJsonPath('message', 'metode tidak didukung');
});

it('serves products and vendors as maps keyed by name, normalised', function () {
    $p = $this->get('/stock-api-mysql/items.php?t=1')->assertOk()->json('products');
    $n = (int) stDb()->selectOne('SELECT COUNT(*) c FROM products')->c;
    expect(count($p))->toBe($n);
    foreach (array_slice($p, 0, 20) as $row) {
        expect($row)->toHaveKeys(['utama', 'cadangan', 'satuan', 'kategori', 'area', 'aktif', 'isi', 'sumber', 'packIsi', 'diOutlet']);
    }
    $v = $this->get('/stock-api-mysql/vendors.php')->assertOk()->json('vendors');
    expect(reset($v))->toHaveKeys(['whatsapp', 'perluJadwalJemput', 'tutupHari', 'penerima', 'bank', 'norek']);
});

it('keeps preserve-if-null on addProduct and follows a rename into HPP', function () {
    stPost('items.php', ['action' => 'addProduct', 'productName' => 'Tes Tepung', 'primaryVendor' => 'A',
        'units' => 'Kg, Gram', 'kategori' => 'DRY', 'area' => 'Bar,bar', 'satuanDasar' => 'Gram', 'isi' => ['Kg' => 1000, 'Dus' => 0]])
        ->assertOk()->assertExactJson(['status' => 'success', 'hppBaru' => true]);
    stPost('items.php', ['action' => 'addProduct', 'productName' => 'Tes Tepung', 'primaryVendor' => 'B'])->assertOk();

    $d = json_decode(stDb()->selectOne("SELECT data FROM products WHERE nama='Tes Tepung'")->data);
    expect($d->utama)->toBe('B')->and($d->satuan)->toBe(['Kg', 'Gram'])->and($d->area)->toBe(['Bar'])
        ->and((array) $d->isi)->toBe(['Kg' => 1000])->and($d->kategori)->toBe('DRY');

    $res = stPost('items.php', ['action' => 'addProduct', 'productName' => 'Tes Tepung 2', 'primaryVendor' => 'B', 'oldProductName' => 'Tes Tepung'])->assertOk();
    expect($res->json('hpp.bahan'))->toBe(1)
        ->and(stDb()->selectOne("SELECT nama FROM products WHERE nama='Tes Tepung'"))->toBeNull()
        ->and(stDb()->selectOne("SELECT nama FROM hpp_bahan WHERE nama='Tes Tepung 2'"))->not->toBeNull();
});

it('forces Pack units on a Central Kitchen product', function () {
    stPost('items.php', ['action' => 'addProduct', 'productName' => 'Tes CK', 'units' => ['Botol'], 'sumber' => 'ck', 'packIsi' => 500, 'packSatuan' => 'Gram'])->assertOk();
    $d = json_decode(stDb()->selectOne("SELECT data FROM products WHERE nama='Tes CK'")->data);
    expect($d->satuan)->toBe(['Pack', 'Gram'])->and($d->sumber)->toBe('ck');
});

it('keeps vendor bank details when a save does not send them', function () {
    stPost('vendors.php', ['action' => 'addVendor', 'vendorName' => 'Tes V', 'vendorPhone' => '1', 'norek' => ' 99 ', 'tutupHari' => '6,0,0,9'])->assertExactJson(['status' => 'success']);
    stPost('vendors.php', ['action' => 'addVendor', 'vendorName' => 'Tes V', 'vendorPhone' => '2'])->assertOk();
    $d = json_decode(stDb()->selectOne("SELECT data FROM vendors WHERE nama='Tes V'")->data);
    expect($d->norek)->toBe('99')->and($d->tutupHari)->toBe([0, 6])->and($d->whatsapp)->toBe('2');
});

it('creates a batch, then joins it summing the same item and unit', function () {
    $res = stPost('orders.php', ['action' => 'batchOrder', 'tim' => 'Bar', 'orders' => [
        ['item' => 'Tes Lemon', 'qty' => 2, 'unit' => 'Kg', 'tglDatang' => '2031-01-02', 'pic' => 'Kru'],
    ]])->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('created', 1);
    $batch = $res->json('batchId');
    expect($batch)->toMatch('/^BATCH-\d{6}-\d{6}-[0-9A-F]{4}$/')
        ->and($res->json('orders.0.nomorOrder'))->toMatch('/^LKS-\d{6}-\d{6}-TES-\d+$/');

    stPost('orders.php', ['action' => 'batchOrder', 'batchId' => $batch, 'orders' => [
        ['item' => 'tes lemon', 'qty' => 1.5, 'unit' => 'Kg', 'note' => 'lagi', 'tglDatang' => '2031-09-09'],
    ]])->assertJsonPath('merged', 1)->assertJsonPath('created', 0);
    $row = stDb()->selectOne('SELECT qty, tgl_datang, data FROM orders WHERE batch_id = ?', [$batch]);
    expect((float) $row->qty)->toBe(3.5)->and($row->tgl_datang)->toBe('2031-01-02')
        ->and(json_decode($row->data)->note)->toBe('lagi');

    stPost('orders.php', ['action' => 'batchOrder', 'batchId' => 'BATCH-NOPE', 'orders' => [['item' => 'X']]])
        ->assertOk()->assertExactJson(['status' => 'error', 'message' => 'batch tujuan tidak ditemukan atau sudah tidak aktif']);
});

it('stamps order numbers and times in WIB', function () {
    $this->travelTo(new DateTime('2031-01-01 20:30:00', new DateTimeZone('UTC')));
    $res = stPost('orders.php', ['action' => 'batchOrder', 'orders' => [['item' => 'Jam', 'qty' => 1, 'unit' => 'Pcs']]]);
    expect($res->json('orders.0.timestamp'))->toBe('2031-01-02 03:30:00')
        ->and($res->json('orders.0.nomorOrder'))->toStartWith('LKS-310102-033000-JAM-');
});

it('archives by order number, syncs data.status, and refuses a bad jemput date for the whole batch', function () {
    $o = stDb()->selectOne("SELECT nomor_order, row_index FROM orders WHERE status = 'Aktif' LIMIT 1");
    stPost('orders.php', ['action' => 'archive', 'orderIds' => [$o->nomor_order]])->assertExactJson(['status' => 'success', 'updated' => 1]);
    expect(json_decode(stDb()->selectOne('SELECT data FROM orders WHERE nomor_order = ?', [$o->nomor_order])->data)->status)->toBe('Arsip');

    stPost('orders.php', ['action' => 'updateTglJemput', 'updates' => [
        ['rowIndex' => $o->row_index, 'tglJemput' => '2031-01-09'], ['rowIndex' => $o->row_index, 'tglJemput' => '9/1'],
    ]])->assertExactJson(['status' => 'error', 'message' => 'format tanggal jemput harus YYYY-MM-DD']);
    expect(json_decode(stDb()->selectOne('SELECT data FROM orders WHERE nomor_order = ?', [$o->nomor_order])->data)->tglJemput ?? null)->not->toBe('2031-01-09');
});

it('syncs Central Kitchen stock on arrival and removes it on cancel (idempotent)', function () {
    $ck = stDb()->selectOne("SELECT o.row_index, o.nomor_order FROM orders o JOIN products p ON p.nama = o.item
        WHERE o.batch_name = 'Central Kitchen' AND p.data LIKE '%\"sumber\":\"ck\"%' LIMIT 1");
    stDb()->delete("DELETE FROM ck_stock WHERE ref = ? AND arah = 'keluar'", [$ck->nomor_order]);

    stPost('orders.php', ['action' => 'updateKedatangan', 'updates' => [['rowIndex' => $ck->row_index, 'kedatangan' => 'Datang', 'catatanAktual' => '']]])
        ->assertJsonPath('ck.disinkron', 1);
    stPost('orders.php', ['action' => 'updateKedatangan', 'updates' => [['rowIndex' => $ck->row_index, 'kedatangan' => 'Datang', 'catatanAktual' => '']]]);
    expect((int) stDb()->selectOne("SELECT COUNT(*) c FROM ck_stock WHERE ref = ? AND arah = 'keluar'", [$ck->nomor_order])->c)->toBe(1);

    stPost('orders.php', ['action' => 'updateKedatangan', 'updates' => [['rowIndex' => $ck->row_index, 'kedatangan' => '']]]);
    expect((int) stDb()->selectOne("SELECT COUNT(*) c FROM ck_stock WHERE ref = ? AND arah = 'keluar'", [$ck->nomor_order])->c)->toBe(0);
});

it('rewrites the stock snapshot but refuses an empty one', function () {
    stPost('stock.php', ['type' => 'stock', 'stock' => new stdClass])->assertOk()->assertExactJson(['status' => 'error', 'message' => 'stock kosong']);
    stPost('stock.php', ['as_of' => '2031-01-01', 'stock' => ['Gula' => ['stock_now' => '2.5', 'stock_unit' => 'Kg']]])
        ->assertExactJson(['status' => 'success', 'saved' => 1, 'as_of' => '2031-01-01']);
    $this->get('/stock-api-mysql/stock.php?type=stock')->assertExactJson(['stock' => ['Gula' => ['stock_now' => 2.5, 'stock_unit' => 'Kg']], 'as_of' => '2031-01-01', 'count' => 1]);
});

it('hides exception messages behind a 500 kesalahan server', function () {
    // an object where a name is expected: legacy's (string) cast threw -> 500
    stPost('items.php', ['action' => 'addProduct', 'productName' => ['a' => 1]])
        ->assertStatus(500)->assertExactJson(['status' => 'error', 'message' => 'kesalahan server']);
});

it('keeps sql_mode to the server, so a non-strict production truncates like legacy', function () {
    expect(config('database.connections.legacy_stock.strict'))->toBeNull();
    stDb()->statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    stPost('orders.php', ['action' => 'batchOrder', 'tim' => str_repeat('T', 30), 'orders' => [['item' => 'Tim Panjang', 'qty' => 1, 'unit' => 'Kg']]])
        ->assertJsonPath('status', 'success');
    expect(stDb()->selectOne("SELECT tim FROM orders WHERE item = 'Tim Panjang'")->tim)->toBe(str_repeat('T', 20));
});

it('scopes teams from the Office session when the switch is on', function () {
    config(['laksamana.stock_batas_per_tim' => false]);
    expect(StockTeamScope::enabled())->toBeFalse();
    config(['laksamana.stock_batas_per_tim' => null, 'laksamana.env_label' => 'dev']);
    expect(StockTeamScope::enabled())->toBeTrue();

    expect(StockTeamScope::teams(['keterangan' => 'Crew Bar, Floor']))->toBe(['Bar', 'Floor'])
        ->and(StockTeamScope::teams(['keterangan' => '']))->toBe([]);
    $sql = 'SELECT 1 WHERE 1';
    $par = [];
    expect(StockTeamScope::apply($sql, $par, ['Bar', '']))->toBeTrue()->and($sql)->toEndWith('AND `tim` IN (?)')->and($par)->toBe(['Bar'])
        ->and(StockTeamScope::apply($sql, $par, []))->toBeFalse()
        ->and(StockTeamScope::apply($sql, $par, null))->toBeTrue();

    // switch on + no provable caller = sees nothing (never "everything")
    expect(app(StockTeamScope::class)->forRequest(null))->toBe([]);
});
