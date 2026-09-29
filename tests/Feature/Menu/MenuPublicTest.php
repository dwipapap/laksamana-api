<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
 * Public /api/v1/menu — the homepage feed (docs/api/menu.md §6). No auth, no
 * legacy database: the rows are greenfield and live in `core`.
 */

beforeEach(function () {
    $this->artisan('menu:seed', ['--fresh' => true])->assertSuccessful();
});

it('serves the 8 food + 9 drink categories and the two Bagian values', function () {
    $r = $this->getJson('/api/v1/menu/jenis')->assertOk();

    expect($r->json('data.makanan.kategori'))->toHaveCount(8)
        ->and($r->json('data.minuman.kategori'))->toHaveCount(9)
        ->and($r->json('data.makanan.bagian'))->toBe([
            ['kunci' => 'main_course', 'nama' => 'Main Course'],
            ['kunci' => 'snack', 'nama' => 'Snack'],
        ])
        ->and($r->json('data.minuman'))->not->toHaveKey('bagian')
        ->and(collect($r->json('data.makanan.kategori'))->pluck('slug'))->toContain('nusantara', 'cake-pastries')
        ->and(collect($r->json('data.minuman.kategori'))->pluck('slug'))->toContain('coffee', 'grab-and-go');
});

it('returns only tampil items in aktif categories, in the allow-list shape', function () {
    $id = menuRow(['slug' => 'nasi-goreng-laksamana']);
    menuVarian($id, 'R', 45000);
    menuVarian($id, 'L', 55000, 2);

    menuRow(['tampil' => false, 'nama' => 'Tidak Tampil']);
    DB::connection('core')->table('menu_kategori')->where('slug', 'western')->update(['aktif' => false]);
    menuRow(['kategori_id' => menuKat('western'), 'nama' => 'Kategori Mati']);

    $r = $this->getJson('/api/v1/menu')->assertOk();
    expect($r->json('meta.total'))->toBe(1);

    $item = $r->json('data.0');
    expect(array_keys($item))->toBe([
        'id', 'slug', 'nama', 'jenis', 'bagian', 'kategori', 'deskripsi', 'komponen', 'foto_url',
        'varian', 'harga_mulai', 'unggulan', 'rekomendasi', 'pedas', 'vegetarian', 'ramah_anak', 'tersedia',
    ])
        ->and($item['jenis'])->toBe('makanan')
        ->and($item['bagian'])->toBe('main_course')
        ->and($item['kategori'])->toBe(['slug' => 'nusantara', 'nama' => 'Nusantara'])
        ->and($item['foto_url'])->toBeNull()
        ->and($item['varian'])->toBe([
            ['label' => 'R', 'harga' => 45000],
            ['label' => 'L', 'harga' => 55000],
        ])
        ->and($item['harga_mulai'])->toBe(45000)
        ->and($item['ramah_anak'])->toBeTrue()
        ->and($item['tersedia'])->toBeTrue();
});

it('filters by jenis, bagian, kategori, q and unggulan', function () {
    menuRow(['slug' => 'nasi-goreng', 'nama' => 'Nasi Goreng Laksamana', 'unggulan' => true]);
    menuRow(['slug' => 'ayam-bakar', 'nama' => 'Ayam Bakar', 'unggulan' => false, 'bagian' => 'snack']);
    $coffee = menuRow(['kategori_id' => menuKat('coffee'), 'bagian' => null, 'nama' => 'Kopi Susu', 'slug' => 'kopi-susu', 'unggulan' => false]);

    expect($this->getJson('/api/v1/menu?jenis=minuman')->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/menu?jenis=minuman')->json('data.0.slug'))->toBe('kopi-susu')
        ->and($this->getJson('/api/v1/menu')->json('meta.total'))->toBe(3)
        ->and($this->getJson('/api/v1/menu?bagian=snack')->json('data.0.slug'))->toBe('ayam-bakar')
        ->and($this->getJson('/api/v1/menu?kategori=coffee')->json('data.0.id'))->toBe($coffee)
        ->and($this->getJson('/api/v1/menu?unggulan=1')->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/menu?q=goreng')->json('data.0.slug'))->toBe('nasi-goreng');
});

it('serves the featured cards and one item by slug, and 404s an unknown slug', function () {
    $id = menuRow(['slug' => 'nasi-goreng-laksamana']);
    menuRow(['unggulan' => false, 'nama' => 'Biasa Saja']);

    expect($this->getJson('/api/v1/menu/unggulan')->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/menu/nasi-goreng-laksamana')->assertOk()->json('data.id'))->toBe($id);

    $this->getJson('/api/v1/menu/tidak-ada')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('is a valid empty answer when nothing is featured', function () {
    menuRow(['unggulan' => false]);

    $this->getJson('/api/v1/menu/unggulan')->assertOk()
        ->assertJson(['data' => [], 'meta' => ['total' => 0]]);
});

it('sends Cache-Control and ETag, and honours If-None-Match with a 304', function () {
    menuRow();

    $r = $this->getJson('/api/v1/menu')->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($r->headers->get('ETag'))->not->toBeNull()
        ->and($r->json('meta.version'))->not->toBe('0');

    $this->withHeaders(['If-None-Match' => $r->headers->get('ETag')])
        ->getJson('/api/v1/menu')->assertStatus(304);

    $id = $r->json('data.0.id');
    DB::connection('core')->table('menu_item')->where('id', $id)->update(['updated_at' => now()->addMinute()]);
    $r2 = $this->getJson('/api/v1/menu')->assertOk();
    expect($r2->headers->get('ETag'))->not->toBe($r->headers->get('ETag'));
});

it('400s a traversal photo key and 404s a missing one', function () {
    $this->get('/api/v1/menu/foto/mn_missing.png')->assertStatus(404);
});
