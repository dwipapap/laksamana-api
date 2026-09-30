<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/helpers.php';

/*
 * Office /api/v1/news/office — the write surface (docs/api/news.md §6).
 * Sanctum + module:news; If-Match on the CRUD PATCH/DELETE rows only.
 */

beforeEach(function () {
    $this->artisan('news:seed', ['--fresh' => true])->assertSuccessful();
    newsSamples();
    $this->app->useStoragePath(base_path('storage/framework/testing/news-storage'));
});

afterEach(function () {
    File::deleteDirectory(base_path('storage/framework/testing/news-storage'));
});

it('requires auth and the module for the office surface', function () {
    $this->getJson('/api/v1/news/office/state')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('bootstraps every article (incl. tampil=false) with internal fields and all categories', function () {
    $t = newsToken();
    newsCreateArtikel();
    newsCreateArtikel(['judul' => 'Draf Saja', 'tampil' => false]);

    $s = $this->withToken($t)->getJson('/api/v1/news/office/state')->assertOk();
    expect($s->json('data.kategori'))->toHaveCount(3)
        ->and($s->json('data.artikel'))->toHaveCount(5) // 3 seeded + 2 created
        ->and(collect($s->json('data.artikel'))->pluck('tampil'))->toContain(false);

    $item = collect($s->json('data.artikel'))->firstWhere('tampil', true);
    expect($item)->toHaveKeys(['kategori_id', 'cover_key', 'tampil', 'version', 'created_at', 'updated_at', 'content', 'published_at']);
});

it('manages categories with generated slugs, 409 duplicates and the in-use guard', function () {
    $t = newsToken();
    $created = $this->withToken($t)->postJson('/api/v1/news/office/kategori', ['nama' => 'Liputan Baru'])
        ->assertCreated()->json('data');
    expect($created['slug'])->toBe('liputan-baru');
    $id = $created['id'];
    $v = $created['version'];

    $this->withToken($t)->postJson('/api/v1/news/office/kategori', ['nama' => 'Liputan Baru'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_exists');

    $this->withToken($t)->patchJson("/api/v1/news/office/kategori/$id", ['nama' => 'Diubah'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');

    $this->withToken($t)->patchJson("/api/v1/news/office/kategori/$id", ['nama' => 'Diubah'], ['If-Match' => (string) $v])
        ->assertOk()->assertJsonPath('data.nama', 'Diubah');

    // a category with articles cannot be deleted
    newsCreateArtikel();
    $katV = DB::connection('core')->table('news_kategori')->where('slug', 'event')->value('version');
    $this->withToken($t)->deleteJson('/api/v1/news/office/kategori/'.newsKat('event'), [], ['If-Match' => (string) $katV])
        ->assertStatus(409)->assertJsonPath('error.code', 'kategori_dipakai');

    // an empty one can
    $v2 = (int) DB::connection('core')->table('news_kategori')->where('id', $id)->value('version');
    $this->withToken($t)->deleteJson("/api/v1/news/office/kategori/$id", [], ['If-Match' => (string) $v2])->assertOk();
    expect(DB::connection('core')->table('news_kategori')->where('id', $id)->exists())->toBeFalse();
});

it('creates, versions and deletes an article', function () {
    $t = newsToken();
    $item = newsCreateArtikel();
    $id = $item['id'];
    $v = $item['version'];

    expect($item['slug'])->toBe('horas-kembali-bergema-di-laksamana-muda')
        ->and($this->withToken($t)->getJson("/api/v1/news/office/artikel/$id")->json('data.version'))->toBe($v);

    // missing If-Match
    $this->withToken($t)->patchJson("/api/v1/news/office/artikel/$id", ['judul' => 'Ganti'])
        ->assertStatus(428)->assertJsonPath('error.code', 'version_required');

    $r = $this->withToken($t)->patchJson("/api/v1/news/office/artikel/$id", [
        'judul' => 'Horas Menggema Lagi',
    ], ['If-Match' => (string) $v])->assertOk();
    expect($r->json('data.title'))->toBe('Horas Menggema Lagi');
    $v2 = $r->json('data.version');
    expect($v2)->toBeGreaterThan($v);

    // stale If-Match
    $this->withToken($t)->patchJson("/api/v1/news/office/artikel/$id", ['judul' => 'Basi'], ['If-Match' => (string) $v])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')
        ->assertJsonPath('error.details.current', $v2);

    $this->withToken($t)->deleteJson("/api/v1/news/office/artikel/$id", [], ['If-Match' => (string) $v2])->assertOk();
    expect(DB::connection('core')->table('news_artikel')->where('id', $id)->exists())->toBeFalse();
});

it('lists office articles with the public filters plus tampil', function () {
    $t = newsToken();
    newsCreateArtikel(['judul' => 'Tampil Baru', 'tampil' => true, 'published_at' => '2026-02-20 10:00:00']);
    newsCreateArtikel(['judul' => 'Draf Baru', 'tampil' => false]);

    expect($this->withToken($t)->getJson('/api/v1/news/office/artikel')->json('meta.total'))->toBe(5)
        ->and($this->withToken($t)->getJson('/api/v1/news/office/artikel?tampil=0')->json('meta.total'))->toBe(1)
        ->and($this->withToken($t)->getJson('/api/v1/news/office/artikel?tampil=0')->json('data.0.title'))->toBe('Draf Baru')
        ->and($this->withToken($t)->getJson('/api/v1/news/office/artikel?category=promo')->json('meta.total'))->toBe(1)
        ->and($this->withToken($t)->getJson('/api/v1/news/office/artikel?q=Draf')->json('data.0.title'))->toBe('Draf Baru');
});

it('validates the article body', function () {
    $t = newsToken();

    // judul required
    $this->withToken($t)->postJson('/api/v1/news/office/artikel', ['kategori_id' => newsKat('event')])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // blank judul
    $this->withToken($t)->postJson('/api/v1/news/office/artikel', ['kategori_id' => newsKat('event'), 'judul' => '  '])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // unknown category
    $this->withToken($t)->postJson('/api/v1/news/office/artikel', ['kategori_id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ', 'judul' => 'Hantu'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // bad published_at
    $this->withToken($t)->postJson('/api/v1/news/office/artikel', [
        'kategori_id' => newsKat('event'), 'judul' => 'Tanggal Rusak', 'published_at' => 'kapan-kapan',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // duplicate slug
    $this->withToken($t)->postJson('/api/v1/news/office/artikel', [
        'kategori_id' => newsKat('event'), 'judul' => 'Sama', 'slug' => 'horas-party-10-tahun',
    ])->assertStatus(409)->assertJsonPath('error.code', 'already_exists');
});

it('publishes with the tampil toggle and stamps published_at when undated', function () {
    $t = newsToken();
    $item = newsCreateArtikel(['judul' => 'Segera Terbit', 'tampil' => false]);
    expect($item['published_at'])->toBeNull();

    // the toggle body must carry its key
    $this->withToken($t)->patchJson("/api/v1/news/office/artikel/{$item['id']}/tampil", [])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // no If-Match needed
    $r = $this->withToken($t)->patchJson("/api/v1/news/office/artikel/{$item['id']}/tampil", ['tampil' => true])->assertOk();
    expect($r->json('data.tampil'))->toBeTrue()
        ->and($r->json('data.published_at'))->not->toBeNull()
        ->and($r->json('data.title'))->toBe('Segera Terbit');

    expect($this->getJson('/api/v1/news')->json('meta.total'))->toBe(4);

    $this->withToken($t)->patchJson("/api/v1/news/office/artikel/{$item['id']}/tampil", ['tampil' => false])->assertOk()
        ->assertJsonPath('data.tampil', false);
    expect($this->getJson('/api/v1/news')->json('meta.total'))->toBe(3);
});

it('saves category display order for any subset and skips unknown ids', function () {
    $t = newsToken();

    $this->withToken($t)->putJson('/api/v1/news/office/urutan', [
        'kategori' => [
            ['id' => newsKat('event'), 'urutan' => 99],
            ['id' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ', 'urutan' => 1],
        ],
    ])->assertOk()->assertJsonPath('data.saved', true);

    expect(DB::connection('core')->table('news_kategori')->where('slug', 'event')->value('urutan'))->toBe(99);
});

it('uploads, streams and deletes a photo; rejects a non-image and an oversized one', function () {
    $t = newsToken();

    $up = $this->withToken($t)->post('/api/v1/news/office/foto', ['file' => newsPng()])->assertCreated();
    $key = $up->json('data.key');
    expect($key)->toMatch('/^nw_[a-f0-9]{8}\.png$/')
        ->and($up->json('data.url'))->toBe('/api/v1/news/foto/'.$key);

    $stream = $this->get('/api/v1/news/foto/'.$key)->assertOk();
    expect($stream->headers->get('Cache-Control'))->toContain('max-age=86400');

    $this->withToken($t)->post('/api/v1/news/office/foto', ['file' => UploadedFile::fake()->create('doc.pdf', 3, 'application/pdf')])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');
    $this->withToken($t)->post('/api/v1/news/office/foto', ['file' => UploadedFile::fake()->create('big.png', 9000, 'image/png')])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_file');

    $this->withToken($t)->deleteJson('/api/v1/news/office/foto/'.$key)->assertOk()->assertJsonPath('data.deleted', true);
    $this->get('/api/v1/news/foto/'.$key)->assertStatus(404);
});

it('sanitizes the stored body: scripts and event handlers never come back', function () {
    $t = newsToken();
    $item = newsCreateArtikel([
        'judul' => 'Coba XSS',
        'tampil' => true,
        'published_at' => '2026-02-20 10:00:00',
        'isi' => '<p>Aman</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">klik</a>',
    ]);

    expect($item['content'])->toContain('<p>Aman</p>')
        ->and($item['content'])->not->toContain('<script>', 'onerror', 'javascript:');

    $public = $this->getJson('/api/v1/news/coba-xss')->assertOk();
    expect($public->json('data.content'))->not->toContain('<script>', 'onerror', 'javascript:');
});

it('seeds idempotently: a second run changes nothing', function () {
    $t = newsToken();
    newsCreateArtikel();

    $before = DB::connection('core')->table('news_artikel')->orderBy('id')->get(['id', 'version', 'slug'])->toArray();
    $kat = DB::connection('core')->table('news_kategori')->orderBy('id')->get(['id', 'version'])->toArray();

    $this->artisan('news:seed')->assertSuccessful();

    expect(DB::connection('core')->table('news_artikel')->orderBy('id')->get(['id', 'version', 'slug'])->toArray())->toEqual($before)
        ->and(DB::connection('core')->table('news_kategori')->orderBy('id')->get(['id', 'version'])->toArray())->toEqual($kat);
});
