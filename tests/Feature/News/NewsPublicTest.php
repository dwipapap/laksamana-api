<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
 * Public /api/v1/news — the homepage rail, listing and detail
 * (docs/api/news.md §6). No auth, no legacy database: the rows are
 * greenfield and live in `core`.
 */

beforeEach(function () {
    $this->artisan('news:seed', ['--fresh' => true])->assertSuccessful();
    newsSamples();
});

it('serves the active categories ordered by urutan', function () {
    $r = $this->getJson('/api/v1/news/kategori')->assertOk();

    expect($r->json('data'))->toBe([
        ['slug' => 'event', 'nama' => 'Event'],
        ['slug' => 'promo', 'nama' => 'Promo'],
        ['slug' => 'kabar', 'nama' => 'Kabar'],
    ])->and($r->json())->not->toHaveKey('meta');
});

it('returns only tampil articles in aktif categories, in the allow-list shape', function () {
    $id = newsRow(['slug' => 'horas-kedua']);

    newsRow(['tampil' => false, 'judul' => 'Draf Saja']);
    DB::connection('core')->table('news_kategori')->where('slug', 'promo')->update(['aktif' => false]);
    newsRow(['kategori_id' => newsKat('promo'), 'judul' => 'Kategori Mati']);

    $r = $this->getJson('/api/v1/news')->assertOk();
    expect($r->json('meta.total'))->toBe(3); // horas + signature + horas-kedua (promo hidden, draft never public)
    expect($r->json('meta.page'))->toBe(1)
        ->and($r->json('meta.per_page'))->toBe(9)
        ->and($r->json('meta.version'))->not->toBe('0');

    $item = collect($r->json('data'))->firstWhere('id', $id);
    expect(array_keys($item))->toBe([
        'id', 'slug', 'category', 'title', 'excerpt', 'date', 'cover', 'author',
    ])
        ->and($item['slug'])->toBe('horas-kedua')
        ->and($item['category'])->toBe(['slug' => 'event', 'nama' => 'Event'])
        ->and($item['title'])->toBe('Horas Kembali Bergema di Laksamana Muda!')
        ->and($item['excerpt'])->toBe('Special Horas Party merayakan 10 tahun berkarya.')
        ->and($item['date'])->toBe('2026-02-16')
        ->and($item['cover'])->toBeNull()
        ->and($item['author'])->toBe('Tim Laksamana');

    $titles = collect($r->json('data'))->pluck('title')->all();
    expect($titles)->not->toContain('Draf Saja', 'Kategori Mati');
});

it('orders newest first and pages the rail contract', function () {
    newsRow(['slug' => 'paling-baru', 'judul' => 'Paling Baru', 'published_at' => '2026-03-01 08:00:00']);
    newsRow(['slug' => 'paling-lama', 'judul' => 'Paling Lama', 'published_at' => '2026-01-01 08:00:00']);

    $r = $this->getJson('/api/v1/news?per_page=3')->assertOk();
    expect($r->json('meta.total'))->toBe(5)
        ->and($r->json('meta.per_page'))->toBe(3)
        ->and(collect($r->json('data'))->pluck('slug')->all())->toBe([
            'paling-baru', 'horas-party-10-tahun', 'captain-wings-series',
        ]);

    $p2 = $this->getJson('/api/v1/news?per_page=3&page=2')->assertOk();
    expect(collect($p2->json('data'))->pluck('slug')->all())->toBe([
        'minuman-signature', 'paling-lama',
    ]);
});

it('filters by category and q, and 422s an unknown category', function () {
    expect($this->getJson('/api/v1/news?category=event')->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/news?category=event')->json('data.0.slug'))->toBe('horas-party-10-tahun')
        ->and($this->getJson('/api/v1/news?q=wings')->json('data.0.slug'))->toBe('captain-wings-series');

    $this->getJson('/api/v1/news?category=tidak-ada')
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('serves one article with its body, and 404s an unknown slug', function () {
    $r = $this->getJson('/api/v1/news/horas-party-10-tahun')->assertOk();
    expect($r->json('data.slug'))->toBe('horas-party-10-tahun')
        ->and($r->json('data.content'))->toContain('Special Horas Party')
        ->and($r->json('data.published_at'))->toBe('2026-02-16T10:00:00+07:00')
        ->and($r->json('meta.version'))->not->toBe('0');

    $this->getJson('/api/v1/news/tidak-ada')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

it('sends Cache-Control and ETag, and honours If-None-Match with a 304', function () {
    $r = $this->getJson('/api/v1/news')->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($r->headers->get('ETag'))->not->toBeNull()
        ->and($r->json('meta.version'))->not->toBe('0');

    $this->withHeaders(['If-None-Match' => $r->headers->get('ETag')])
        ->getJson('/api/v1/news')->assertStatus(304);

    $id = $r->json('data.0.id');
    DB::connection('core')->table('news_artikel')->where('id', $id)->update(['updated_at' => now()->addMinute()]);
    $r2 = $this->getJson('/api/v1/news')->assertOk();
    expect($r2->headers->get('ETag'))->not->toBe($r->headers->get('ETag'));
});

it('resolves a cover to its foto path and 404s a missing file', function () {
    newsRow(['slug' => 'dengan-cover', 'cover_key' => 'nw_missing.png']);

    $r = $this->getJson('/api/v1/news/dengan-cover')->assertOk();
    expect($r->json('data.cover'))->toBe('/api/v1/news/foto/nw_missing.png');

    $this->get('/api/v1/news/foto/nw_missing.png')->assertStatus(404);
});
