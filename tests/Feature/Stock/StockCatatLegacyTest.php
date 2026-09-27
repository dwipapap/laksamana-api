<?php

use App\Modules\Stock\Services\StockSupport;
use App\Support\Modules;
use Illuminate\Testing\TestResponse;

/* #35: ck.php, usage.php, waste.php, serah.php, opname.php, log.php */

function ctPost(string $file, mixed $body): TestResponse
{
    return test()->call('POST', '/stock-api-mysql/'.$file, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=utf-8'],
        is_string($body) ? $body : json_encode($body));
}

function ctDb()
{
    return Modules::db('stock');
}

it('serves the CK balance (never date-filtered) and the filtered movements', function () {
    $all = $this->get('/stock-api-mysql/ck.php')->assertOk()->json();
    $some = $this->get('/stock-api-mysql/ck.php?dari=2099-01-01')->assertOk()->json();
    expect($some['saldo'])->toBe($all['saldo'])->and($some['mutasi'])->toBe([])
        ->and(count($all['mutasi']))->toBe((int) ctDb()->selectOne(StockSupport::q('SELECT COUNT(*) c FROM {ck_stock}'))->c);
    $row = collect($all['saldo'])->firstWhere('item', 'Ayam Hainan');
    expect($row)->toHaveKeys(['sumber', 'packIsi', 'packSatuan', 'masuk', 'keluar', 'saldo', 'terakhir'])
        ->and($row['saldo'])->toEqual($row['masuk'] - $row['keluar']);
});

it('converts a Pack to the base unit from the master and refuses non-CK goods', function () {
    ctPost('ck.php', ['action' => 'simpan', 'item' => 'Gula', 'arah' => 'masuk', 'qtyInput' => 1])
        ->assertExactJson(['status' => 'error', 'message' => 'barang bukan barang Central Kitchen: Gula']);
    $id = ctPost('ck.php', ['action' => 'simpan', 'item' => 'Ayam Hainan', 'arah' => 'MASUK', 'qtyInput' => 2, 'unitInput' => 'pack', 'sebab' => 'aneh', 'tanggal' => '2031-01-01'])
        ->assertOk()->json('id');
    expect($id)->toMatch('/^CK-\d{6}-\d{6}-[0-9A-F]{6}$/');
    $r = ctDb()->selectOne(StockSupport::q('SELECT qty, qty_input, sebab, ref FROM {ck_stock} WHERE {id} = ?'), [$id]);
    expect((float) $r->qty)->toBe(2400.0)->and($r->sebab)->toBe('produksi')->and($r->ref)->toBeNull();

    ctPost('ck.php', ['action' => 'hapus', 'id' => $id])->assertExactJson(['status' => 'success', 'deleted' => 1]);
});

it('answers a CK movement saved with an id as an edit, refusing unknown and order-sync rows (#113)', function () {
    // unknown id: a clean legacy error, never a 500, and nothing is written
    ctPost('ck.php', ['action' => 'simpan', 'id' => 'CK-X', 'item' => 'Ayam Hainan', 'arah' => 'masuk', 'qtyInput' => 1])
        ->assertExactJson(['status' => 'error', 'message' => 'mutasi tidak ditemukan']);
    expect(ctDb()->selectOne(StockSupport::q('SELECT {id} AS id FROM {ck_stock} WHERE {id} = ?'), ['CK-X']))->toBeNull();

    // an own row is edited in place, like the v1 PATCH
    $id = ctPost('ck.php', ['action' => 'simpan', 'item' => 'Ayam Hainan', 'arah' => 'masuk', 'qtyInput' => 1])->json('id');
    ctPost('ck.php', ['action' => 'simpan', 'id' => $id, 'item' => 'Ayam Hainan', 'arah' => 'keluar', 'qtyInput' => 2])
        ->assertExactJson(['status' => 'success', 'id' => $id]);
    expect(ctDb()->selectOne(StockSupport::q('SELECT arah FROM {ck_stock} WHERE {id} = ?'), [$id]))->arah->toBe('keluar');

    // an order-sync row only changes through its check-in
    $ref = ctDb()->selectOne(StockSupport::q('SELECT {id} AS id FROM {ck_stock} WHERE ref IS NOT NULL LIMIT 1'))->id;
    ctPost('ck.php', ['action' => 'simpan', 'id' => $ref, 'item' => 'Ayam Hainan', 'arah' => 'keluar', 'qtyInput' => 2])
        ->assertExactJson(['status' => 'error', 'message' => 'mutasi dari pengajuan hanya berubah lewat check-in']);
});

it('protects order-sync rows and only lets outlet goods be sent to CK', function () {
    $ref = ctDb()->selectOne(StockSupport::q('SELECT {id} AS `id` FROM {ck_stock} WHERE ref IS NOT NULL LIMIT 1'))->id;
    ctPost('ck.php', ['action' => 'hapus', 'id' => $ref])
        ->assertExactJson(['status' => 'error', 'message' => 'mutasi dari pengajuan hanya hilang bila check-in dibatalkan']);

    ctDb()->insert('INSERT INTO '.StockSupport::table('products')." (nama, data) VALUES ('Tes CK Saja', '{\"sumber\":\"ck\",\"diOutlet\":false}')");
    ctPost('ck.php', ['action' => 'kirim', 'item' => 'Tes CK Saja', 'qtyInput' => 1])
        ->assertJsonPath('message', 'barang ini tidak disimpan di outlet, jadi tidak bisa dikirim ke CK');
    $id = ctPost('ck.php', ['action' => 'kirim', 'item' => 'Dimsum Ayam', 'qtyInput' => 2, 'unitInput' => 'Pack'])->json('id');
    $r = ctDb()->selectOne(StockSupport::q('SELECT arah, qty, sebab, status FROM {ck_stock} WHERE {id} = ?'), [$id]);
    expect($r->arah)->toBe('masuk')->and((float) $r->qty)->toBe(18.0)->and($r->sebab)->toBe('kiriman')->and($r->status)->toBe('');
});

it('saves, re-statuses and deletes an event usage record', function () {
    ctPost('usage.php', ['action' => 'simpan', 'tanggal' => '2031-01-01', 'jenis' => 'Event', 'items' => [['item' => ' ']]])
        ->assertExactJson(['status' => 'error', 'message' => 'minimal satu bahan harus diisi']);
    $id = ctPost('usage.php', ['action' => 'simpan', 'tanggal' => '2031-01-01', 'jenis' => 'Event', 'namaEvent' => 'Tes',
        'items' => [['item' => 'Gula', 'qty' => '1.5']]])->assertOk()->json('id');
    ctPost('usage.php', ['action' => 'status', 'id' => $id, 'status' => 'Selesai'])->assertExactJson(['status' => 'success']);
    $list = $this->get('/stock-api-mysql/usage.php?dari=2031-01-01&ke=2031-01-01')->json();
    expect($list)->toHaveCount(1)->and($list[0]['status'])->toBe('Selesai')->and($list[0]['items'][0])->toBe(['item' => 'Gula', 'qty' => 1.5, 'unit' => '', 'note' => '']);
    ctPost('usage.php', ['action' => 'hapus', 'id' => $id])->assertExactJson(['status' => 'success']);
    ctPost('usage.php', ['action' => 'hapus', 'id' => $id])->assertExactJson(['status' => 'error', 'message' => 'tidak ditemukan']);
});

it('keeps a waste photo out of the list, keeps it on edit unless sent, and serves it alone', function () {
    $id = ctPost('waste.php', ['action' => 'simpan', 'tanggal' => '2031-01-01', 'item' => 'Roti', 'qty' => 1, 'foto' => 'data:x', 'fotoNama' => 'a.png'])->json('id');
    $row = collect($this->get('/stock-api-mysql/waste.php?dari=2031-01-01')->json())->firstWhere('id', $id);
    expect($row)->not->toHaveKey('foto')->and($row['adaFoto'])->toBeTrue();

    ctPost('waste.php', ['action' => 'simpan', 'id' => $id, 'tanggal' => '2031-01-01', 'item' => 'Roti', 'qty' => 2])->assertJsonPath('id', $id);
    $this->get('/stock-api-mysql/waste.php?action=foto&id='.$id)->assertExactJson(['status' => 'success', 'foto' => 'data:x', 'fotoNama' => 'a.png']);
    ctPost('waste.php', ['action' => 'simpan', 'id' => $id, 'tanggal' => '2031-01-01', 'item' => 'Roti', 'qty' => 2, 'foto' => '']);
    $this->get('/stock-api-mysql/waste.php?action=foto&id='.$id)->assertExactJson(['status' => 'error', 'message' => 'foto tidak ada']);
});

it('requires a photo for a new handover, and answers the edit with success (#113)', function () {
    $body = ['action' => 'simpan', 'tanggal' => '2031-01-01', 'tujuan' => 'Bar', 'items' => [['item' => 'Gula', 'qty' => 1]]];
    ctPost('serah.php', $body)->assertExactJson(['status' => 'error', 'message' => 'foto bukti wajib diunggah']);
    $id = ctPost('serah.php', $body + ['foto' => 'data:y'])->assertOk()->json('id');

    ctPost('serah.php', ['id' => $id, 'penerima' => 'Diedit'] + $body)
        ->assertExactJson(['status' => 'success', 'id' => $id]);
    expect(ctDb()->selectOne(StockSupport::q('SELECT penerima, foto FROM {serah_terima} WHERE {id} = ?'), [$id]))
        ->penerima->toBe('Diedit')->foto->toBe('data:y');

    // an unknown id is a clean legacy error, never a 500
    ctPost('serah.php', ['id' => 'SRH-NOPE', 'penerima' => 'X'] + $body)
        ->assertExactJson(['status' => 'error', 'message' => 'catatan tidak ditemukan']);
});

it('stores opname lines as sent, unfilled numbers as null', function () {
    $id = ctPost('opname.php', ['action' => 'simpan', 'tanggal' => '2031-01-01', 'status' => 'Selesai',
        'items' => [['item' => 'Gula', 'opening' => '2', 'masuk' => '', 'sistem' => 0, 'fisik' => null, 'selisih' => 9]]])->json('id');
    $it = $this->get('/stock-api-mysql/opname.php?dari=2031-01-01')->json('0.items.0');
    expect($it)->toBe(['item' => 'Gula', 'unit' => '', 'opening' => 2, 'masuk' => null, 'sistem' => 0, 'fisik' => null, 'note' => '']);
    ctPost('opname.php', ['action' => 'simpan', 'id' => 'OPN-NOPE', 'tanggal' => '2031-01-01', 'items' => [['item' => 'X']]])
        ->assertExactJson(['status' => 'error', 'message' => 'opname tidak ditemukan']);
    ctPost('opname.php', ['action' => 'hapus', 'id' => $id])->assertExactJson(['status' => 'success']);
});

it('logs entries with the server clock and reads them back newest first, limited', function () {
    $this->travelTo(new DateTime('2031-01-01 20:30:00', new DateTimeZone('UTC')));
    ctPost('log.php', ['action' => 'catat', 'entri' => [
        ['modul' => 'ordering', 'aksi' => 'tes_log', 'aktor' => 'Kru', 'ringkas' => str_repeat('x', 600), 'waktu' => '1999-01-01'],
        ['modul' => '', 'aksi' => 'x'],
    ]])->assertExactJson(['status' => 'success', 'dicatat' => 1]);
    $r = $this->get('/stock-api-mysql/log.php?q=tes_log&dari=2031-01-02')->assertOk()->json('0');
    expect($r['waktu'])->toBe('2031-01-02 03:30:00')->and(mb_strlen($r['ringkas']))->toBe(500)->and($r['data'])->toBe([]);
    expect($this->get('/stock-api-mysql/log.php?limit=5000')->json())->toHaveCount(min(300, (int) ctDb()->selectOne(StockSupport::q('SELECT COUNT(*) c FROM {activity_log}'))->c));
});

it('scopes usage, waste and serah lists to the caller\'s teams from ?sesi=', function () {
    config(['laksamana.stock_batas_per_tim' => true]);
    ctDb()->insert(StockSupport::q('INSERT INTO {waste} ({id},')."tanggal,item,tim,waktu,foto,data) VALUES ('WST-T1','2031-02-01','A','Kitchen','','','{}'), ('WST-T2','2031-02-01','B','Bar','','','{}')");
    $sesi = legacySesi(officeUser('u-arif')); // Bar crew, not a usage admin
    $ids = array_column($this->get('/stock-api-mysql/waste.php?dari=2031-02-01&sesi='.$sesi)->json(), 'id');
    expect($ids)->toBe(['WST-T2']);
});

it('shows nothing to an unproven caller when team scoping is on', function () {
    config(['laksamana.stock_batas_per_tim' => true]);
    expect($this->get('/stock-api-mysql/serah.php')->assertOk()->json())->toBe([]);
});
