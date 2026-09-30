<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Shared fixtures for the news tests. News is greenfield and lives in
 * `core`, so rows are inserted straight there (no legacy importer exists).
 * `published_at` is stored in UTC and rendered in WIB (NewsCatalog::TZ).
 */

/** A Sanctum token for the superadmin test user (module:news passes via '*'). */
function newsToken(): string
{
    return loginAs(officeUser('u-wandi'));
}

/** Id of a category seeded by `news:seed`. */
function newsKat(string $slug): string
{
    return (string) DB::connection('core')->table('news_kategori')->where('slug', $slug)->value('id');
}

/** Insert one article directly into core. Returns its id. */
function newsRow(array $over = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::connection('core')->table('news_artikel')->insert(array_merge([
        'id' => $id,
        'kategori_id' => newsKat('event'),
        'judul' => 'Horas Kembali Bergema di Laksamana Muda!',
        'slug' => 'artikel-'.Str::lower(Str::random(8)),
        'ringkasan' => 'Special Horas Party merayakan 10 tahun berkarya.',
        'isi' => '<p>Special Horas Party.</p>',
        'cover_key' => null,
        'penulis' => 'Tim Laksamana',
        'tampil' => true,
        'published_at' => '2026-02-16 03:00:00', // 10:00 WIB
        'created_at' => now(),
        'updated_at' => now(),
        'version' => 1,
    ], $over));

    return $id;
}

/**
 * The three published sample articles the tests read against. news.json
 * ships categories only, so the tests insert these themselves.
 */
function newsSamples(): void
{
    newsRow(['slug' => 'horas-party-10-tahun', 'published_at' => '2026-02-16 03:00:00']);
    newsRow([
        'slug' => 'captain-wings-series',
        'kategori_id' => newsKat('promo'),
        'judul' => 'Wings Baru yang Lagi Jadi Rebutan di Meja Nongkrong?!',
        'ringkasan' => 'Captain Wings Series: tiga varian rasa baru.',
        'isi' => '<p>Captain Wings Series.</p>',
        'published_at' => '2026-02-05 05:00:00',
    ]);
    newsRow([
        'slug' => 'minuman-signature',
        'kategori_id' => newsKat('kabar'),
        'judul' => 'Kenalan dengan Minuman Signature Kami',
        'ringkasan' => 'Koleksi minuman baru racikan bar.',
        'isi' => '<p>Minuman signature.</p>',
        'published_at' => '2026-02-05 02:00:00',
    ]);
}

/** Create one article through the office API; returns the office shape. */
function newsCreateArtikel(array $over = []): array
{
    return test()->withToken(newsToken())->postJson('/api/v1/news/office/artikel', array_merge([
        'kategori_id' => newsKat('event'),
        'judul' => 'Horas Kembali Bergema di Laksamana Muda!',
        'isi' => '<p>Special Horas Party.</p>',
    ], $over))->assertCreated()->json('data');
}

/** A real 1x1 PNG (so the upload path sees an image mime). */
function newsPng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'news.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
    );
}
