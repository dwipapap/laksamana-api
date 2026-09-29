<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/helpers.php';

/*
 * Office /api/v1/menu/office — the write surface (docs/api/menu.md §6).
 * Sanctum + module:menu; If-Match on the CRUD PATCH/DELETE rows only.
 */

beforeEach(function () {
    $this->artisan('menu:seed', ['--fresh' => true])->assertSuccessful();
    $this->app->useStoragePath(base_path('storage/framework/testing/menu-storage'));
});

afterEach(function () {
    File::deleteDirectory(base_path('storage/framework/testing/menu-storage'));
});

it('requires auth and the module for the office surface', function () {
    $this->getJson('/api/v1/menu/office/state')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('bootstraps every item (incl. tampil=false), internal fields and all categories', function () {
    $t = menuToken();
    menuCreateItem();
    menuCreateItem(['nama' => 'Sembunyi', 'tampil' => false]);

    $s = $this->withToken($t)->getJson('/api/v1/menu/office/state')->assertOk();
    expect($s->json('data.kategori'))->toHaveCount(17)
        ->and($s->json('data.item'))->toHaveCount(2)
        ->and(collect($s->json('data.item'))->pluck('tampil'))->toContain(false)
        ->and($s->json('data.bagian'))->toBe([
            ['kunci' => 'main_course', 'nama' => 'Main Course'],
            ['kunci' => 'snack', 'nama' => 'Snack'],
        ]);

    $item = collect($s->json('data.item'))->firstWhere('tampil', true);
    expect($item)->toHaveKeys(['kategori_id', 'tampil', 'urutan', 'catatan_internal', 'version'])
        ->and($item['varian'][0])->toHaveKeys(['id', 'label', 'harga', 'urutan']);
});

it('manages categories with generated slugs, 409 duplicates and the in-use guard', function () {
    $t = menuToken();
    $created = $this->withToken($t)->postJson('/api/v1/menu/office/kategori', ['jenis' => 'makanan', 'nama' => 'Test Baru'])
        ->assertCreated()->json('data');
    expect($created['slug'])->toBe('test-baru');
    $id = $created['id'];
    $v = $created['version'];

    $this->withToken($t)->postJson('/api/v1/menu/office/kategori', ['jenis' => 'makanan', 'nama' => 'Test Baru'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_exists');

    $this->withToken($t)->patchJson("/api/v1/menu/office/kategori/$id", ['nama' => 'Diubah'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');

    $this->withToken($t)->patchJson("/api/v1/menu/office/kategori/$id", ['nama' => 'Diubah'], ['If-Match' => (string) $v])
        ->assertOk()->assertJsonPath('data.nama', 'Diubah');

    // a category with items cannot be deleted
    menuCreateItem();
    $katV = DB::connection('core')->table('menu_kategori')->where('slug', 'nusantara')->value('version');
    $this->withToken($t)->deleteJson('/api/v1/menu/office/kategori/'.menuKat('nusantara'), [], ['If-Match' => (string) $katV])
        ->assertStatus(409)->assertJsonPath('error.code', 'kategori_dipakai');

    // an empty one can
    $v2 = (int) DB::connection('core')->table('menu_kategori')->where('id', $id)->value('version');
    $this->withToken($t)->deleteJson("/api/v1/menu/office/kategori/$id", [], ['If-Match' => (string) $v2])->assertOk();
    expect(DB::connection('core')->table('menu_kategori')->where('id', $id)->exists())->toBeFalse();
});

it('creates, versions, replaces variants and deletes an item', function () {
    $t = menuToken();
    $item = menuCreateItem();
    $id = $item['id'];
    $v = $item['version'];

    expect($item['harga_mulai'])->toBe(45000)
        ->and($item['varian'])->toHaveCount(1)
        ->and($this->withToken($t)->getJson("/api/v1/menu/office/item/$id")->json('data.version'))->toBe($v);

    // missing If-Match
    $this->withToken($t)->patchJson("/api/v1/menu/office/item/$id", ['nama' => 'Ganti'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');

    // a varian array REPLACES the whole set
    $r = $this->withToken($t)->patchJson("/api/v1/menu/office/item/$id", [
        'nama' => 'Nasi Goreng Spesial',
        'varian' => [['label' => 'R', 'harga' => 50000], ['label' => 'L', 'harga' => 60000, 'urutan' => 2]],
    ], ['If-Match' => (string) $v])->assertOk();
    expect($r->json('data.nama'))->toBe('Nasi Goreng Spesial')
        ->and($r->json('data.varian'))->toHaveCount(2)
        ->and($r->json('data.harga_mulai'))->toBe(50000);
    $v2 = $r->json('data.version');
    expect($v2)->toBeGreaterThan($v);

    // stale If-Match
    $this->withToken($t)->patchJson("/api/v1/menu/office/item/$id", ['nama' => 'Basi'], ['If-Match' => (string) $v])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')
        ->assertJsonPath('error.details.current', $v2);

    $this->withToken($t)->deleteJson("/api/v1/menu/office/item/$id", [], ['If-Match' => (string) $v2])->assertOk();
    expect(DB::connection('core')->table('menu_item')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::connection('core')->table('menu_varian')->where('item_id', $id)->count())->toBe(0);
});

it('lists office items with the public filters plus tampil and unggulan', function () {
    $t = menuToken();
    menuCreateItem(['nama' => 'Featured', 'unggulan' => true]);
    menuCreateItem(['nama' => 'Hidden', 'tampil' => false]);
    menuCreateItem(['nama' => 'Normal']);

    expect($this->withToken($t)->getJson('/api/v1/menu/office/item')->json('meta.total'))->toBe(3)
        ->and($this->withToken($t)->getJson('/api/v1/menu/office/item?tampil=0')->json('meta.total'))->toBe(1)
        ->and($this->withToken($t)->getJson('/api/v1/menu/office/item?tampil=0')->json('data.0.nama'))->toBe('Hidden')
        ->and($this->withToken($t)->getJson('/api/v1/menu/office/item?unggulan=1')->json('meta.total'))->toBe(1)
        ->and($this->withToken($t)->getJson('/api/v1/menu/office/item?q=Featured')->json('data.0.nama'))->toBe('Featured');
});

it('validates bagian against the category jenis and rejects bad categories', function () {
    $t = menuToken();

    // makanan without bagian
    $this->withToken($t)->postJson('/api/v1/menu/office/item', ['kategori_id' => menuKat('nusantara'), 'nama' => 'Tanpa Bagian'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // minuman with bagian
    $this->withToken($t)->postJson('/api/v1/menu/office/item', ['kategori_id' => menuKat('coffee'), 'nama' => 'Kopi Salah', 'bagian' => 'main_course'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // unknown category
    $this->withToken($t)->postJson('/api/v1/menu/office/item', ['kategori_id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ', 'nama' => 'Hantu', 'bagian' => 'main_course'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // negative price
    $this->withToken($t)->postJson('/api/v1/menu/office/item', [
        'kategori_id' => menuKat('nusantara'), 'nama' => 'Harga Salah', 'bagian' => 'main_course',
        'varian' => [['label' => '', 'harga' => -1]],
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('flips tersedia and tampil without touching anything else', function () {
    $t = menuToken();
    $item = menuCreateItem();

    $r = $this->withToken($t)->patchJson("/api/v1/menu/office/item/{$item['id']}/tersedia", ['tersedia' => false])->assertOk();
    expect($r->json('data.tersedia'))->toBeFalse()
        ->and($r->json('data.tampil'))->toBeTrue()
        ->and($r->json('data.nama'))->toBe('Nasi Goreng Laksamana');

    expect($this->getJson('/api/v1/menu')->json('data.0.tersedia'))->toBeFalse();

    $this->withToken($t)->patchJson("/api/v1/menu/office/item/{$item['id']}/tampil", ['tampil' => false])->assertOk()
        ->assertJsonPath('data.tampil', false);
    expect($this->getJson('/api/v1/menu')->json('meta.total'))->toBe(0);
});

it('saves display order for any subset', function () {
    $t = menuToken();
    $item = menuCreateItem();

    $this->withToken($t)->putJson('/api/v1/menu/office/urutan', [
        'item' => [['id' => $item['id'], 'urutan' => 5]],
        'kategori' => [['id' => menuKat('nusantara'), 'urutan' => 99]],
    ])->assertOk();

    expect(DB::connection('core')->table('menu_item')->where('id', $item['id'])->value('urutan'))->toBe(5)
        ->and(DB::connection('core')->table('menu_kategori')->where('slug', 'nusantara')->value('urutan'))->toBe(99);
});

it('uploads, streams and deletes a photo; rejects a non-image and an oversized one', function () {
    $t = menuToken();

    $up = $this->withToken($t)->post('/api/v1/menu/office/foto', ['file' => menuPng()])->assertCreated();
    $key = $up->json('data.key');
    expect($key)->toMatch('/^mn_[a-f0-9]{8}\.png$/')
        ->and($up->json('data.url'))->toBe('/api/v1/menu/foto/'.$key);

    $stream = $this->get('/api/v1/menu/foto/'.$key)->assertOk();
    expect($stream->headers->get('Cache-Control'))->toContain('max-age=86400');

    $this->withToken($t)->post('/api/v1/menu/office/foto', ['file' => UploadedFile::fake()->create('doc.pdf', 3, 'application/pdf')])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');
    $this->withToken($t)->post('/api/v1/menu/office/foto', ['file' => UploadedFile::fake()->create('big.png', 9000, 'image/png')])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');

    $this->withToken($t)->deleteJson('/api/v1/menu/office/foto/'.$key)->assertOk()->assertJsonPath('data.deleted', true);
    $this->get('/api/v1/menu/foto/'.$key)->assertStatus(404);
});

it('seeds idempotently: a second run changes nothing', function () {
    $t = menuToken();
    menuCreateItem();

    $before = DB::connection('core')->table('menu_item')->orderBy('id')->get(['id', 'version', 'slug'])->toArray();
    $kat = DB::connection('core')->table('menu_kategori')->orderBy('id')->get(['id', 'version'])->toArray();

    $this->artisan('menu:seed')->assertSuccessful();

    expect(DB::connection('core')->table('menu_item')->orderBy('id')->get(['id', 'version', 'slug'])->toArray())->toEqual($before)
        ->and(DB::connection('core')->table('menu_kategori')->orderBy('id')->get(['id', 'version'])->toArray())->toEqual($kat);
});
