<?php

use App\Modules\Stock\Services\StockSupport;
use App\Support\Modules;
use Illuminate\Testing\TestResponse;

/* #36: hpp.php (compat) and /api/v1/stock/hpp. u-jb: every Modul; u-wandi: admin; u-adit: no hpp. */

function hpPost(mixed $body): TestResponse
{
    return test()->call('POST', '/stock-api-mysql/hpp.php', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=utf-8'], json_encode($body));
}

function hpDb()
{
    return Modules::db('stock');
}

it('serves the flat all payload with derived per1 and default settings', function () {
    $all = $this->get('/stock-api-mysql/hpp.php?action=all')->assertOk()->json();
    expect(array_keys($all))->toBe(['bahan', 'resep', 'setting', 'ts'])
        ->and(count($all['bahan']))->toBe((int) hpDb()->selectOne(StockSupport::q('SELECT COUNT(*) c FROM {hpp_bahan}'))->c)
        ->and($all['setting'])->toMatchArray(['targetFood' => 0.33, 'buffer' => 0.05, 'lampuMerah' => 8]);
    $b = collect($all['bahan'])->first(fn ($x) => $x['qty_beli'] > 0);
    expect($b['per1'])->toEqual($b['harga_beli'] / $b['qty_beli']);
});

it('answers a thrown error as 500 with the reason, like legacy', function () {
    hpPost(['action' => 'simpanBahan', 'data' => ['nama' => ' ']])
        ->assertStatus(500)->assertExactJson(['status' => 'error', 'message' => 'kesalahan server: Nama bahan kosong']);
    hpPost(['action' => 'simpanPakai', 'data' => ['bulan' => '2026-7']])
        ->assertStatus(500)->assertJsonPath('message', 'kesalahan server: Bulan tidak sah (YYYY-MM)');
});

it('renames an ingredient into every recipe line and the monthly usage', function () {
    $used = hpDb()->selectOne(StockSupport::q("SELECT COUNT(*) c FROM {hpp_resep} WHERE bahan LIKE '%\"nama\":\"Brisket\"%'"))->c;
    hpDb()->insert(StockSupport::q("INSERT INTO {hpp_pakai} (bulan,bahan) VALUES ('2031-01','Brisket')"));
    hpPost(['action' => 'simpanBahan', 'by' => 'Kru', 'data' => ['nama' => 'Brisket Baru', 'namaLama' => 'Brisket', 'di_purchasing' => false]])
        ->assertOk()->assertExactJson(['status' => 'success', 'saved' => true, 'nama' => 'Brisket Baru', 'resepIkutBerubah' => $used]);
    expect(hpDb()->selectOne(StockSupport::q("SELECT nama FROM {hpp_bahan} WHERE nama='Brisket'")))->toBeNull()
        ->and(hpDb()->selectOne(StockSupport::q("SELECT bahan FROM {hpp_pakai} WHERE bulan='2031-01'"))->bahan)->toBe('Brisket Baru');
});

it('registers a new ingredient as a Purchasing product, and a CK recipe as a ck product', function () {
    hpPost(['action' => 'simpanBahan', 'data' => ['nama' => 'Tes HPP Baru', 'satuan' => 'Gram']])->assertJsonPath('purchasingBaru', true);
    expect(json_decode(hpDb()->selectOne(StockSupport::q("SELECT data FROM {products} WHERE nama='Tes HPP Baru'"))->data)->satuan)->toBe(['Gram']);

    $id = hpPost(['action' => 'simpanResep', 'data' => ['nama' => 'Tes Base CK', 'yield_qty' => 0, 'yield_unit' => 'Ml', 'kode' => 'ab1', 'di_purchasing' => 1,
        'bahan' => [['nama' => 'Gula', 'qty' => '5'], ['catatan' => 'saring'], 7]]])->assertJsonPath('purchasingBaru', true)->assertJsonPath('bahan', 2)->json('id');
    $r = hpDb()->selectOne(StockSupport::q('SELECT yield_qty, kode, bahan FROM {hpp_resep} WHERE {id} = ?'), [$id]);
    expect((float) $r->yield_qty)->toBe(1.0)->and($r->kode)->toBe('AB1')
        ->and(json_decode($r->bahan, true))->toBe([['nama' => 'Gula', 'qty' => 5, 'satuan' => '', 'ref' => 'bahan'], ['catatan' => 'saring']])
        ->and(json_decode(hpDb()->selectOne(StockSupport::q("SELECT data FROM {products} WHERE nama='Tes Base CK'"))->data)->sumber)->toBe('ck');
});

it('refuses the one-time import when HPP holds data', function () {
    hpPost(['action' => 'impor', 'data' => ['bahan' => []]])->assertOk()->assertJsonPath('status', 'error')
        ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Data HPP sudah ada ('));
});

it('imports ingredients by name without renaming, counting new vs changed', function () {
    hpPost(['action' => 'imporBahan', 'rows' => [['nama' => 'gula', 'harga_beli' => 1, 'namaLama' => 'X'], ['nama' => 'Tes Impor'], ['nama' => ''], 5]])
        ->assertExactJson(['status' => 'success', 'saved' => true, 'baru' => 1, 'diubah' => 1, 'dilewati' => 2, 'galat' => []]);
});

it('merges and saves the month and settings', function () {
    hpPost(['action' => 'simpanBahan', 'data' => ['nama' => 'Tes Gabung', 'di_purchasing' => false]]);
    hpPost(['action' => 'gabungBahan', 'dari' => 'Tes Gabung', 'ke' => 'Gula'])->assertJsonPath('status', 'success');
    hpPost(['action' => 'simpanPakai', 'data' => ['bulan' => '2031-02', 'penjualan' => 'Rp 1.000', 'baris' => [['bahan' => 'Gula', 'sa' => '2']]]])->assertJsonPath('baris', 1);
    $this->get('/stock-api-mysql/hpp.php?action=pakai&bulan=2031-02')->assertJsonPath('penjualan', 1)->assertJsonPath('baris.0.sa', 2); // 'Rp 1.000' reads as 1.0, as legacy
    hpPost(['action' => 'simpanSetting', 'data' => ['buffer' => '0.1', 'x' => 1]])->assertJsonPath('setting.buffer', 0.1)->assertJsonMissingPath('setting.x');
});

// ─────────────────────────── v1 ──

it('keeps /stock/hpp to the hpp Modul', function () {
    $this->withToken(loginAs(officeUser('u-adit')))->getJson('/api/v1/stock/hpp/ingredients')->assertStatus(403);
});

it('keeps the destructive import to HPP admins', function () {
    $this->withToken(loginAs(officeUser('u-andry')))->postJson('/api/v1/stock/hpp/import', ['bahan' => []])->assertStatus(403);
});

it('creates, patches, renames and deletes an ingredient with versions (by = the acting user)', function () {
    $t = loginAs(officeUser('u-jb'));
    $c = $this->withToken($t)->postJson('/api/v1/stock/hpp/ingredients', ['nama' => 'V1 Bahan', 'qty_beli' => 1000, 'harga_beli' => 20000, 'di_purchasing' => false])
        ->assertCreated()->assertJsonPath('data.per1', 20)->assertJsonPath('data.updated_by', officeUser('u-jb')['name']);
    $this->withToken($t)->postJson('/api/v1/stock/hpp/ingredients', ['nama' => 'v1 bahan'])->assertStatus(409);
    $this->withToken($t)->patchJson('/api/v1/stock/hpp/ingredients/V1 Bahan', ['harga_beli' => 1])->assertStatus(428);

    $p = $this->withToken($t)->withHeader('If-Match', $c->json('meta.version'))
        ->patchJson('/api/v1/stock/hpp/ingredients/V1 Bahan', ['harga_beli' => 30000, 'nama' => 'V1 Bahan Baru'])
        ->assertOk()->assertJsonPath('data.nama', 'V1 Bahan Baru')->assertJsonPath('data.qty_beli', 1000)->assertJsonPath('data.di_purchasing', 0);
    $this->flushHeaders();
    $this->withToken($t)->withHeader('If-Match', $c->json('meta.version'))->deleteJson('/api/v1/stock/hpp/ingredients/V1 Bahan Baru')->assertStatus(409);
    $this->flushHeaders();
    $this->withToken($t)->withHeader('If-Match', $p->json('meta.version'))->deleteJson('/api/v1/stock/hpp/ingredients/V1 Bahan Baru')->assertOk();
    expect(hpDb()->selectOne(StockSupport::q("SELECT nama FROM {hpp_bahan} WHERE nama LIKE 'V1 Bahan%'")))->toBeNull();
});

it('refuses a rename onto a name another ingredient holds', function () {
    $t = loginAs(officeUser('u-jb'));
    $v = $this->withToken($t)->getJson('/api/v1/stock/hpp/ingredients/Gula Merah')->assertOk()->json('meta.version');
    $this->withToken($t)->withHeader('If-Match', $v)->patchJson('/api/v1/stock/hpp/ingredients/Gula Merah', ['nama' => 'gula'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_exists');
});

it('creates and patches a recipe keeping the fields not sent', function () {
    $t = loginAs(officeUser('u-jb'));
    $c = $this->withToken($t)->postJson('/api/v1/stock/hpp/recipes', ['nama' => 'V1 Resep', 'tipe' => 'dish', 'harga_baru' => 25000,
        'bahan' => [['nama' => 'Gula', 'qty' => 10, 'satuan' => 'Gram']]])->assertCreated()->assertJsonPath('data.tipe', 'dish');
    $id = $c->json('data.id');
    $this->withToken($t)->postJson('/api/v1/stock/hpp/recipes', ['id' => $id, 'nama' => 'Dup'])->assertStatus(409);
    $this->withToken($t)->withHeader('If-Match', $c->json('meta.version'))->patchJson("/api/v1/stock/hpp/recipes/$id", ['harga_baru' => 27000])
        ->assertOk()->assertJsonPath('data.harga_baru', 27000)->assertJsonPath('data.tipe', 'dish')->assertJsonPath('data.bahan.0.nama', 'Gula');
    $this->flushHeaders();
    $this->withToken($t)->postJson('/api/v1/stock/hpp/recipes', ['nama' => ''])->assertStatus(422)->assertJsonPath('error.message', 'Nama resep kosong');
});

it('saves a month and the settings under versions', function () {
    $t = loginAs(officeUser('u-jb'));
    $m = $this->withToken($t)->getJson('/api/v1/stock/hpp/usage/2031-03')->assertOk()->assertJsonPath('data.baris', []);
    $this->withToken($t)->withHeader('If-Match', $m->json('meta.version'))
        ->patchJson('/api/v1/stock/hpp/usage/2031-03', ['penjualan' => 5000000, 'baris' => [['bahan' => 'Gula', 'sa' => 3, 'opname' => 1]]])
        ->assertOk()->assertJsonPath('data.penjualan', 5000000)->assertJsonPath('data.baris.0.opname', 1);
    $this->flushHeaders();
    $this->withToken($t)->withHeader('If-Match', $m->json('meta.version'))->patchJson('/api/v1/stock/hpp/usage/2031-03', ['catatan' => 'x'])->assertStatus(409);
    $this->flushHeaders();
    $s = $this->withToken($t)->getJson('/api/v1/stock/hpp/settings')->assertOk();
    $this->withToken($t)->withHeader('If-Match', $s->json('meta.version'))->patchJson('/api/v1/stock/hpp/settings', ['targetDrink' => 0.4])
        ->assertOk()->assertJsonPath('data.targetDrink', 0.4);
});

it('refuses the import (409) while HPP holds data, for an admin', function () {
    $this->withToken(loginAs(officeUser('u-wandi')))->postJson('/api/v1/stock/hpp/import', ['bahan' => [['nama' => 'X']]])
        ->assertStatus(409)->assertJsonPath('error.code', 'hpp_not_empty');
});
