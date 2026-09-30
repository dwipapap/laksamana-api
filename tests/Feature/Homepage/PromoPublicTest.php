<?php

use App\Modules\Bd\Services\BdState;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/helpers.php';

/*
 * Public /api/v1/homepage/promos — the website promo slider (docs/api/homepage.md
 * §Public Promo). No auth: only switched-on AND eligible rows; BD promos must be
 * running with a valid poster; uploads follow their optional WIB window. The
 * image is served by row id only, never by key or BD data URL.
 */

beforeEach(function () {
    $this->app->useStoragePath(base_path('storage/framework/testing/homepage-storage'));
});

afterEach(function () {
    File::deleteDirectory(base_path('storage/framework/testing/homepage-storage'));
});

it('serves only switched-on eligible rows, in manual order, with the allow-list shape', function () {
    $wib = fn () => now('Asia/Jakarta');
    $running = bdPromo(['nama' => 'Kopi Kenangan']);
    $upcoming = bdPromo([
        'nama' => 'Belum Mulai',
        'mulai' => $wib()->addDay()->toDateString(),
        'selesai' => $wib()->addDays(3)->toDateString(),
    ]);
    $ended = bdPromo([
        'nama' => 'Sudah Berakhir',
        'mulai' => $wib()->subDays(5)->toDateString(),
        'selesai' => $wib()->subDay()->toDateString(),
    ]);
    $paused = bdPromo(['nama' => 'Dijeda', 'paused' => true]);
    $noPoster = bdPromo(['nama' => 'Tanpa Poster', 'poster' => '']);
    bdSetPromos([$running, $upcoming, $ended, $paused, $noPoster]);

    $bdRow = bannerRow(['sumber' => 'bd', 'promo_id' => $running['id'], 'alt' => null, 'tampil' => true, 'urutan' => 20]);
    $upload = bannerRow(['alt' => 'Banner Unggahan', 'tampil' => true, 'urutan' => 30]);
    $off = bannerRow(['alt' => 'Disimpan Off', 'tampil' => false, 'urutan' => 10]);
    bannerRow(['sumber' => 'bd', 'promo_id' => $upcoming['id'], 'tampil' => true, 'urutan' => 40]);
    bannerRow(['sumber' => 'bd', 'promo_id' => $ended['id'], 'tampil' => true, 'urutan' => 50]);
    bannerRow(['sumber' => 'bd', 'promo_id' => $paused['id'], 'tampil' => true, 'urutan' => 60]);
    bannerRow(['sumber' => 'bd', 'promo_id' => $noPoster['id'], 'tampil' => true, 'urutan' => 70]);

    $r = $this->getJson('/api/v1/homepage/promos')->assertOk();

    expect(collect($r->json('data'))->pluck('id')->all())->toBe([$bdRow->id, $upload->id]);

    $first = $r->json('data.0');
    expect(array_keys($first))->toBe(['id', 'sumber', 'image', 'alt', 'href'])
        ->and($first['sumber'])->toBe('bd')
        ->and($first['image'])->toBe('/api/v1/homepage/promos/'.$bdRow->id.'/gambar')
        ->and($first['alt'])->toBe($running['nama']) // a BD row without its own alt uses nama
        ->and($first['href'])->toBeNull();

    $second = $r->json('data.1');
    expect($second['sumber'])->toBe('unggah')
        ->and($second['alt'])->toBe('Banner Unggahan')
        ->and($second['image'])->toBe('/api/v1/homepage/promos/'.$upload->id.'/gambar');

    // the switched-off row and the ineligible BD rows are nowhere in the body
    $body = (string) $r->getContent();
    expect($body)->not->toContain($off->id)
        ->not->toContain('Belum Mulai')
        ->not->toContain('Sudah Berakhir')
        ->not->toContain('Dijeda')
        ->not->toContain('Tanpa Poster');
});

it('orders rows with the same urutan by the older row first', function () {
    $older = bannerRow(['alt' => 'Dulu', 'tampil' => true, 'urutan' => 10]);
    $newer = bannerRow(['alt' => 'Baru', 'tampil' => true, 'urutan' => 10]);
    DB::connection('core')->table('homepage_banner')->where('id', $older->id)
        ->update(['created_at' => now()->subDay()]);

    expect(collect($this->getJson('/api/v1/homepage/promos')->json('data'))->pluck('id')->all())
        ->toBe([$older->id, $newer->id]);
});

it('keeps an upload in the list only inside its optional WIB window', function () {
    $wib = fn () => now('Asia/Jakarta');
    $today = $wib()->toDateString();

    $in = bannerRow(['tampil' => true, 'mulai' => $today, 'selesai' => $today, 'urutan' => 10]);
    bannerRow(['tampil' => true, 'mulai' => $wib()->addDay()->toDateString(), 'urutan' => 20]);
    bannerRow(['tampil' => true, 'selesai' => $wib()->subDay()->toDateString(), 'urutan' => 30]);
    $forever = bannerRow(['tampil' => true, 'urutan' => 40]);

    expect(collect($this->getJson('/api/v1/homepage/promos')->json('data'))->pluck('id')->all())
        ->toBe([$in->id, $forever->id]);
});

it('serves the image only for a switched-on eligible row, by banner id', function () {
    $upload = bannerRow(['tampil' => true, 'gambar_key' => homepagePhoto('hb_uji.jpg', 'GAMBAR-UNGGAHAN')]);
    $off = bannerRow(['tampil' => false, 'gambar_key' => homepagePhoto('hb_off.jpg', 'JANGAN-KELUAR')]);

    $r = $this->get('/api/v1/homepage/promos/'.$upload->id.'/gambar')->assertOk();
    expect($r->getContent())->toBe('GAMBAR-UNGGAHAN')
        ->and($r->headers->get('Content-Type'))->toContain('image/jpeg')
        ->and($r->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($r->headers->get('ETag'))->not->toBeNull();

    $this->withHeaders(['If-None-Match' => $r->headers->get('ETag')])
        ->get('/api/v1/homepage/promos/'.$upload->id.'/gambar')->assertStatus(304);

    // a switched-off row never leaks its file
    $this->get('/api/v1/homepage/promos/'.$off->id.'/gambar')->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    // a BD data URL is decoded server-side; the raw data URL never ships
    $bd = bdPromo();
    bdSetPromos([$bd]);
    $row = bannerRow(['sumber' => 'bd', 'promo_id' => $bd['id'], 'tampil' => true]);
    $img = $this->get('/api/v1/homepage/promos/'.$row->id.'/gambar')->assertOk();
    expect($img->getContent())->toBe('JPEGBODY')
        ->and($img->headers->get('Content-Type'))->toContain('image/jpeg');

    // a malformed data URL counts as "no poster": no image, not in the list
    $bad = bdPromo(['poster' => 'data:image/svg+xml;base64,'.base64_encode('<svg/>')]);
    bdSetPromos([$bad]);
    $badRow = bannerRow(['sumber' => 'bd', 'promo_id' => $bad['id'], 'tampil' => true]);
    $this->get('/api/v1/homepage/promos/'.$badRow->id.'/gambar')->assertStatus(404);
    expect(collect($this->getJson('/api/v1/homepage/promos')->json('data'))->pluck('id')->all())
        ->not->toContain($badRow->id);

    // a vanished file is not served either
    $gone = bannerRow(['tampil' => true, 'gambar_key' => 'hb_hilang.jpg']);
    File::delete(storage_path('app/homepage/hb_hilang.jpg'));
    $this->get('/api/v1/homepage/promos/'.$gone->id.'/gambar')->assertStatus(404);
});

it('never leaks the secret BD fields into the public body', function () {
    $bd = bdPromo(['nama' => 'Promo Terbuka']);
    bdSetPromos([$bd]);
    bannerRow(['sumber' => 'bd', 'promo_id' => $bd['id'], 'tampil' => true, 'alt' => null]);

    $body = (string) $this->getJson('/api/v1/homepage/promos')->assertOk()->getContent();

    foreach (['PARTNER_RAHASIA', 'KODE_RAHASIA', 'PIC_RAHASIA', 'Syarat rahasia', 'kode', 'partner', 'lmPIC'] as $secret) {
        expect($body)->not->toContain($secret);
    }
    expect($body)->toContain('Promo Terbuka'); // alt fallback = nama
});

it('sends Cache-Control and an ETag, and honours If-None-Match with a 304', function () {
    bannerRow(['tampil' => true]);

    $r = $this->getJson('/api/v1/homepage/promos')->assertOk();
    expect($r->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($r->headers->get('ETag'))->not->toBeNull()
        ->and($r->json('meta.version'))->not->toBe('0');

    $this->withHeaders(['If-None-Match' => $r->headers->get('ETag')])
        ->getJson('/api/v1/homepage/promos')->assertStatus(304);
});

it('keeps serving uploaded banners when BD cannot be read', function () {
    $this->app->instance(BdState::class, new class extends BdState
    {
        public function setting(string $k, mixed $default): mixed
        {
            throw new RuntimeException('BD down');
        }
    });

    $keep = bannerRow(['tampil' => true, 'urutan' => 10]);
    $bd = bdPromo();
    bdSetPromos([$bd]);
    bannerRow(['sumber' => 'bd', 'promo_id' => $bd['id'], 'tampil' => true, 'urutan' => 20]);

    expect(collect($this->getJson('/api/v1/homepage/promos')->json('data'))->pluck('id')->all())
        ->toBe([$keep->id]);
});

it('answers an empty list when nothing is switched on', function () {
    bannerRow(['tampil' => false]);

    $this->getJson('/api/v1/homepage/promos')->assertOk()->assertJson(['data' => []]);
});
